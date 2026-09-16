<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Exceptions\InvalidStructuralDocument;
use App\Document\Structure\LegacyStructureBridge;
use App\Document\Structure\StructuralDocument;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrateur de la coexistence entre les deux pipelines documentaires.
 *
 * Rôle unique : décider QUEL pipeline traite un document, exécuter la
 * conversion, et **revenir à l'ancien en cas d'échec** — jamais de document
 * perdu à cause d'un bug du nouveau parseur.
 *
 * Trois modes (voir `config/document.php`) :
 *
 * | Mode | Comportement |
 * |------|--------------|
 * | `false` | Ancien pipeline seul (défaut, éprouvé en production) |
 * | `true`  | Nouveau pipeline seul (parseur OOXML natif) |
 * | `'auto'`| Nouveau pipeline, **repli automatique** sur l'ancien en cas d'échec |
 *
 * Le mode `auto` est le cœur de la stratégie de migration : il permet
 * d'observer le nouveau pipeline en conditions réelles sans aucun risque pour
 * les utilisateurs, puis de le promouvoir quand les données sont rassurantes.
 */
final class DocumentPipeline
{
    /** Pipeline historique : lecture par PHPWord (`app/DocAnalyzer`). */
    public const LEGACY = 'legacy';

    /** Pipeline de la refonte : lecture OOXML native (`app/Document`). */
    public const NATIVE = 'native';

    public function __construct(
        private readonly DocxNativeAdapter $adapter,
        private readonly LegacyStructureBridge $bridge,
    ) {}

    /**
     * Le nouveau pipeline est-il autorisé pour ce traitement ?
     */
    public function isNativeEnabled(): bool
    {
        return config('document.pipeline.v2') !== false;
    }

    /**
     * Le mode est-il « auto » (nouveau pipeline avec repli) ?
     */
    public function isAutoMode(): bool
    {
        return config('document.pipeline.v2') === 'auto';
    }

    /**
     * Convertit un fichier `.docx` avec le nouveau pipeline.
     *
     * @param  string  $filePath  Chemin absolu du fichier
     * @param  string  $documentId  Identifiant du document (traçabilité)
     * @param  null|Closure(StructuralDocument): StructuralDocument  $classifier
     *                                                                            Étape de classification optionnelle, appliquée APRÈS la conversion.
     *                                                                            Elle est reçue en paramètre plutôt qu'instanciée ici pour que
     *                                                                            l'orchestrateur reste ignorant de la classification : c'est ce qui
     *                                                                            permet à `app/Document` hors `Classification/` de rester sans
     *                                                                            référence à l'IA (contrainte d'architecture vérifiée par test).
     * @return null|StructuralDocument null si la conversion a échoué
     *                                 (l'appelant décide alors du repli)
     */
    public function convert(string $filePath, string $documentId, ?Closure $classifier = null): ?StructuralDocument
    {
        if (! $this->isNativeEnabled()) {
            return null;
        }

        // Ne traiter que ce que l'adaptateur sait lire : un PDF ou un `.doc`
        // ancien ne doit pas être passé au parseur OOXML.
        if (! $this->adapter->supports($filePath)) {
            if ($this->isAutoMode()) {
                // En mode auto, un format non supporté n'est pas une erreur :
                // l'ancien pipeline le gère.
                return null;
            }

            Log::warning('Pipeline natif : format non supporté par l\'adaptateur DOCX', [
                'document_id' => $documentId,
                'file' => basename($filePath),
            ]);

            return null;
        }

        try {
            $document = $this->adapter->convert($filePath, $documentId);

            // Classification (R2) : étape facultative, fournie par l'appelant.
            //
            // Elle est isolée dans son propre `try` à dessein. Sans cela, une
            // défaillance de classification (clé API absente, modèle
            // indisponible, réponse illisible) remonterait au `catch` extérieur
            // qui, en mode `true`, RELANCE l'exception — et le document serait
            // perdu alors que sa conversion, elle, a réussi. La règle du
            // projet (« la classification ne perd jamais de contenu ») exige
            // que l'échec de l'étape optionnelle laisse la structure
            // déterministe intacte.
            if ($classifier !== null) {
                try {
                    $document = $classifier($document);
                } catch (Throwable $e) {
                    Log::warning('Pipeline natif : classification échouée, structure déterministe conservée', [
                        'document_id' => $documentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Pipeline natif : conversion réussie', [
                'document_id' => $documentId,
                'blocks' => $document->count(),
                'headings' => count($document->headings()),
                'ambiguous' => count($document->ambiguous()),
            ]);

            return $document;
        } catch (Throwable $e) {
            // En mode `true`, l'échec est un vrai problème : on le log en
            // erreur pour qu'il soit visible. En mode `auto`, c'est attendu
            // pendant la transition : une alerte suffit.
            Log::log(
                $this->isAutoMode() ? 'warning' : 'error',
                'Pipeline natif : conversion échouée',
                [
                    'document_id' => $documentId,
                    'file' => basename($filePath),
                    'error' => $e->getMessage(),
                    'fallback' => $this->isAutoMode() ? 'legacy' : 'aucun',
                ]
            );

            if ($this->isAutoMode()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Convertit une structure historique en JSON structurel.
     *
     * Utilisé pour les documents traités AVANT l'activation du nouveau
     * pipeline : leurs 35 structures existent en base au format historique et
     * doivent rester exploitables (décision D6).
     *
     * @param  array<string, mixed>  $legacy
     * @return null|array{document: StructuralDocument, warnings: array<int, string>}
     *                                                                                null si la structure est vide
     */
    public function fromLegacy(array $legacy, string $documentId): ?array
    {
        if ($legacy === []) {
            return null;
        }

        try {
            return $this->bridge->toStructural($legacy, $documentId);
        } catch (InvalidStructuralDocument $e) {
            Log::warning('Bridge legacy : conversion impossible', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::error('Bridge legacy : erreur inattendue', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sérialise un document structurel pour la persistance.
     *
     * @param  array<string, mixed>  $meta  Métadonnées supplémentaires à
     *                                      sérialiser dans `structural_json.meta` (rapport de classification,
     *                                      paramètres d'exécution…). Sans ce passage, le rapport de R2
     *                                      n'existerait nulle part : `toArray()` ne sérialise que ce que le
     *                                      document porte, et le rapport est produit à côté de lui.
     * @return null|array{structural_json: array<string, mixed>, schema_version: int, pipeline: string}
     *                                                                                                  null si la persistance est désactivée
     */
    public function forPersistence(StructuralDocument $document, string $pipeline, array $meta = []): ?array
    {
        if (! config('document.persist_structural', true)) {
            return null;
        }

        $serialise = $document->toArray();

        if ($meta !== []) {
            // Fusion NON destructive : les clés déjà présentes dans le document
            // (produites par le parseur) font foi sur les métadonnées ajoutées.
            $serialise['meta'] = [...$meta, ...($serialise['meta'] ?? [])];
        }

        return [
            'structural_json' => $serialise,
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => $pipeline,
        ];
    }
}
