<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\DocAnalyzer\DocAnalyzer;
use App\DocAnalyzer\DocumentReconstructor;
use App\Document\Editing\DocumentEditingService;
use App\Document\Editing\ToolWhitelist;
use App\Models\User;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Detection\StructureCorrectionService;
use App\Services\Detection\TextExtractionService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * Outils actionnables exposés au chat IA (exigence A).
 *
 * Le modèle (via function calling OpenRouter) peut demander l'exécution
 * d'actions concrètes au lieu de se contenter de répondre en texte :
 *
 * OUTILS INTERNES (exécution locale, PHPWord / LibreOffice) :
 *   - document_reconstruct : reconstruit un DOCX depuis une structure
 *                            détectée (reconstructeur)
 *   - table_of_contents    : génère un sommaire (champ TOC PhpWord)
 *   - structure_correct    : applique des corrections de structure
 *
 * OUTILS EXTERNES (via OpenRouter) :
 *   - web_search           : recherche web native (web_search_options)
 *   - image_generate       : génération d'image (gpt-image-*)
 *
 * Chaque outil est décrit par un schéma OpenAI (tools[]) et exécuté par
 * execute() : c'est la cible du callable `executor` passé à
 * OpenRouterService::chat().
 */
class ChatToolsService
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly DocumentEditService $documentEditor,
        private readonly ?ClaudeSkillsService $claudeSkills = null,
        private readonly ?TextExtractionService $textExtraction = null,
        private readonly ?DocumentEditingService $structuralEditor = null,
    ) {}

    /**
     * Identifiant du processus détenteur du verrou d'édition.
     *
     * Le verrou exige un propriétaire : un même utilisateur qui relance une
     * action doit pouvoir rafraîchir son propre verrou, tandis qu'un second
     * processus doit être bloqué. On identifie donc le processus par son
     * utilisateur et son canal, pas par une constante globale.
     */
    private function editOwner(?User $user): string
    {
        return 'chat:'.($user?->id ?? 'anonyme');
    }

    /**
     * Liste des identifiants d'outils actifs (utilisée pour le menu
     * « Outils » de l'interface).
     *
     * @return string[]
     */
    public function availableTools(): array
    {
        return array_values(array_map(
            fn (array $schema): string => (string) ($schema['function']['name'] ?? ''),
            $this->schemas(),
        ));
    }

    /**
     * Schémas OpenAI (function calling) de tous les outils.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_reconstruct',
                    'description' => 'Reconstruit un document DOCX complet à partir d\'une structure JSON '
                        .'(titres, sous-titres, en-têtes, pieds de page, tableaux, images, légendes) '
                        .'en appliquant la convention rapport de stage (frontispice romain, corps arabe, TOC).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'structure' => ['type' => 'object', 'description' => 'Structure détectée à reconstruire'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => ['structure'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_analyze',
                    'description' => 'ANALYSE la structure d\'une pièce jointe DOCX (chat/attachments/...) : '
                        .'détection des titres, sous-titres, en-têtes, pieds de page, tableaux, images, '
                        .'légendes. Utilise les outils internes (règles Word + regex) et, si disponible, '
                        .'l\'IA. source_path = chemin de la pièce jointe (visible dans le contexte). '
                        .'Retourne un résumé de la structure détectée.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_path' => ['type' => 'string', 'description' => 'Chemin chat/attachments/... de la pièce jointe à analyser'],
                            'method' => ['type' => 'string', 'enum' => ['regex', 'ia'], 'description' => 'Méthode de détection : regex (rapide, hors-ligne) ou ia (plus précise, plus lente)'],
                        ],
                        'required' => ['source_path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_to_docx',
                    'description' => 'Convertit une pièce jointe PDF (chat/attachments/...) en DOCX éditable '
                        .'(via LibreOffice headless) et enregistre le DOCX généré (téléchargeable). '
                        .'source_path = chemin de la pièce jointe (visible dans le contexte).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_path' => ['type' => 'string', 'description' => 'Chemin chat/attachments/... du PDF à convertir'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => ['source_path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'structure_correct',
                    'description' => 'Corrige une structure détectée en promouvant / rétrogradant des titres '
                        .'ou en retirant des items du plan. '
                        .'Arguments : structure (JSON), corrections (map clé de position → action '
                        .'1|2|3|remove).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'structure' => ['type' => 'object', 'description' => 'Structure à corriger'],
                            'corrections' => ['type' => 'object', 'description' => 'Corrections à appliquer'],
                        ],
                        'required' => ['structure', 'corrections'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'table_of_contents',
                    'description' => 'Génère un document DOCX contenant un sommaire (champ TOC PhpWord '
                        .'niveaux 1-3, mis à jour à l\'ouverture via updateFields). '
                        .'Arguments : title (intitulé du sommaire, défaut SOMMAIRE), output_filename.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Intitulé du sommaire (défaut : SOMMAIRE)'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Effectue une recherche web en temps réel (recommandé pour vérifier '
                        .'une information récente ou des sources). Retourne des extraits sourcés.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Requête de recherche'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'image_generate',
                    'description' => 'Génère une image (schéma, illustration, couverture visuelle) à partir '
                        .'d\'une description textuelle. Génère et enregistre le fichier dans le stockage.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => ['type' => 'string', 'description' => 'Description détaillée de l\'image à générer'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier image généré (sans extension)'],
                        ],
                        'required' => ['prompt'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_edit',
                    'description' => 'MODIFIE une pièce jointe DOCX (chat/attachments/...) fournie par '
                        .'l\'utilisateur : remplacement de texte (replace_text), édition d\'un titre '
                        .'(edit_title), ajout d\'un paragraphe (append_text), changement de niveau de '
                        .'titre (change_title_level), changement de couleur de titre '
                        .'(change_title_color), changement de police (change_font), mise en forme '
                        .'complète (format_complete : police, taille, interligne, titres). Le fichier '
                        .'source n\'est jamais modifié : le résultat est un NOUVEAU fichier '
                        .'téléchargeable. source_path = chemin de la pièce jointe (visible dans le '
                        .'contexte).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_path' => ['type' => 'string', 'description' => 'Chemin chat/attachments/... de la pièce jointe à modifier'],
                            'operation' => ['type' => 'string', 'enum' => ['replace_text', 'edit_title', 'append_text', 'change_title_level', 'change_title_color', 'change_font', 'format_complete'], 'description' => 'Opération d\'édition'],
                            'search' => ['type' => 'string', 'description' => 'Texte à rechercher (replace_text)'],
                            'replacement' => ['type' => 'string', 'description' => 'Texte de remplacement (replace_text)'],
                            'title_number' => ['type' => 'integer', 'description' => 'Numéro du titre à modifier, 1 = premier (edit_title, change_title_level, change_title_color)'],
                            'new_text' => ['type' => 'string', 'description' => 'Nouveau texte du titre (edit_title)'],
                            'new_level' => ['type' => 'integer', 'description' => 'Nouveau niveau du titre 1-6 (change_title_level)'],
                            'color' => ['type' => 'string', 'description' => 'Couleur du titre : hex (FF0000) ou nom français (rouge, bleu…) (change_title_color)'],
                            'font_name' => ['type' => 'string', 'description' => 'Nom de la police à appliquer, ex. Times New Roman (change_font, format_complete)'],
                            'font_size' => ['type' => 'number', 'description' => 'Taille de police en points (format_complete)'],
                            'line_spacing' => ['type' => 'number', 'description' => 'Interligne : 1.0, 1.15, 1.5, 2.0 (format_complete)'],
                            'title_color' => ['type' => 'string', 'description' => 'Couleur des titres pour la mise en forme complète (format_complete)'],
                            'title_level' => ['type' => 'integer', 'description' => 'Niveau de titre global 1-6 (format_complete)'],
                            'text' => ['type' => 'string', 'description' => 'Paragraphe à ajouter à la fin (append_text)'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier résultat (sans extension)'],
                        ],
                        'required' => ['source_path', 'operation'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_to_pdf',
                    'description' => 'Convertit une pièce jointe DOCX (chat/attachments/...) en PDF '
                        .'(via LibreOffice headless) et enregistre le PDF généré (téléchargeable). '
                        .'source_path = chemin de la pièce jointe (visible dans le contexte).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'source_path' => ['type' => 'string', 'description' => 'Chemin chat/attachments/... du DOCX à convertir'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier PDF généré (sans extension)'],
                        ],
                        'required' => ['source_path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document_create',
                    'description' => 'GÉNÈRE un document vierge (Word .docx ou PDF) à partir d\'un contenu '
                        .'Markdown simple (# titres, - listes, paragraphes) : idéal pour créer un rapport, '
                        .'un compte-rendu, un courrier, des notes structurées. '
                        .'Arguments : content (Markdown), format (word|pdf, défaut word), output_filename.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string', 'description' => 'Contenu en Markdown simple (# titre, ## sous-titre, - liste, texte)'],
                            'format' => ['type' => 'string', 'enum' => ['word', 'pdf'], 'description' => 'Format de sortie (word = .docx, pdf = .pdf)'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier généré (sans extension)'],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],

            // Tools d'édition structurelle (R6) : ils modifient un document
            // ANALYSÉ et persisté, contrairement à document_edit qui travaille
            // sur une pièce jointe. Les schémas sont dérivés de la liste blanche
            // (`ToolWhitelist`), donc un tool retiré de l'autorisation ne peut pas
            // rester exposé au modèle.
            ...EditToolSchemas::all(),
        ];
    }

    /**
     * Exécute un appel d'outil demandé par le modèle.
     *
     * @param  array<string, mixed>  $toolCall  {id, name, arguments (string JSON)}
     * @param  User|null  $user  Utilisateur connecté (nullable)
     * @param  string  $planSlug  Plan courant pour le routage des modèles
     * @return array{result?: string, error?: string}
     */
    public function execute(array $toolCall, ?User $user, string $planSlug): array
    {
        $name = (string) ($toolCall['name'] ?? '');
        $arguments = $this->decodeArguments($toolCall['arguments'] ?? '{}');

        Log::info('Chat : exécution outil', [
            'tool' => $name,
            'user_id' => $user?->id,
            'arguments' => $arguments,
        ]);

        // --- Tools d'édition structurelle (R6) --------------------------------
        // Traités AVANT le match général : ils passent par la liste blanche et
        // l'orchestrateur, alors que le match ne connaît que les outils
        // historiques. Un tool hors liste blanche reçoit ici un refus EXPLICITE
        // (§9.14) plutôt qu'un « outil inconnu » qui laisserait croire à une
        // erreur de frappe.
        if (ToolWhitelist::allows($name)) {
            return $this->executeStructuralEdit($name, $arguments, $user);
        }

        try {
            $result = match ($name) {
                'document_reconstruct' => $this->reconstructDocument($arguments),
                'structure_correct' => $this->correctStructure($arguments),
                'table_of_contents' => $this->generateTableOfContents($arguments),
                'web_search' => $this->webSearch($arguments, $planSlug),
                'image_generate' => $this->generateImage($arguments, $planSlug),
                'document_edit' => $this->documentEditor->apply($arguments),
                'document_to_pdf' => $this->documentEditor->apply($arguments),
                'document_to_docx' => $this->documentEditor->apply($arguments),
                'document_analyze' => $this->analyzeDocument($arguments),
                'document_create' => $this->createDocument($arguments),
                default => ['error' => "Outil inconnu : {$name}"],
            };

            // FALLBACK CLAUDE (Q5) : si un outil DOCUMENT interne échoue
            // (document_edit, document_to_pdf, document_to_docx) et que
            // l'utilisateur est éligible aux Skills Claude, on relance la
            // demande via Claude Skills (docx/pdf) avec les mêmes arguments.
            if (isset($result['error'])
                && in_array($name, ['document_edit', 'document_to_pdf', 'document_to_docx'], true)
                && $this->claudeSkills !== null
                && $this->claudeSkills->isEligible($user)) {
                return $this->claudeFallback($name, $arguments, $result['error'], $user);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Échec d\'exécution d\'un outil du chat', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            // FALLBACK CLAUDE sur exception : l'outil interne a échoué
            if (in_array($name, ['document_edit', 'document_to_pdf', 'document_to_docx'], true)
                && $this->claudeSkills !== null
                && $this->claudeSkills->isEligible($user)) {
                return $this->claudeFallback($name, $arguments, $e->getMessage(), $user);
            }

            return ['error' => "Échec de l'outil {$name} : ".$e->getMessage()];
        }
    }

    /**
     * Exécute un tool d'édition structurelle (phase R6.14–R6.16).
     *
     * Deux responsabilités que l'orchestrateur laisse volontairement à
     * l'appelant :
     *
     *  1. **traduire la demande de confirmation** en réponse lisible. Le modèle
     *     doit comprendre qu'il lui faut l'accord de l'utilisateur, et non
     *     réessayer — sinon il boucle et consomme des tokens sans effet ;
     *  2. **proposer l'annulation** après une action destructive réussie : c'est
     *     le seul moment où l'utilisateur sait qu'il vient de se passer quelque
     *     chose d'irréversible.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result?: string, error?: string}
     */
    private function executeStructuralEdit(string $name, array $arguments, ?User $user): array
    {
        if ($this->structuralEditor === null) {
            return ['error' => 'L’édition structurelle n’est pas disponible dans cette configuration.'];
        }

        // Identifiant du document : sans lui, aucun tool ne peut s'exécuter. On
        // le vérifie ici pour renvoyer un message utile plutôt que laisser le
        // validateur produire une erreur technique.
        $documentId = $arguments['document_id'] ?? null;

        if (! is_numeric($documentId)) {
            return ['error' => 'Précise le document à modifier : ces outils agissent sur un document '
                .'analysé, identifié par son numéro. Pour un fichier joint à la conversation, '
                .'utilise plutôt document_edit.'];
        }

        $documentId = (int) $documentId;

        // --- Annulation demandée explicitement ---------------------------------
        if ($name === 'undo_last_action') {
            $annulation = $this->structuralEditor->undo($documentId);

            return $annulation['restored']
                ? ['result' => $annulation['summary']]
                : ['error' => (string) $annulation['error']];
        }

        // La confirmation est portée par l'argument `confirmed`, que le modèle
        // ne peut positionner qu'après avoir obtenu l'accord de l'utilisateur.
        $confirmed = (bool) ($arguments['confirmed'] ?? false);

        $resultat = $this->structuralEditor->apply(
            $documentId,
            $name,
            $arguments,
            $this->editOwner($user),
            $confirmed,
        );

        if ($resultat['error'] !== null) {
            return ['error' => (string) $resultat['error']];
        }

        if ($resultat['confirmation_required']) {
            $taille = (int) ($resultat['details']['affected_blocks'] ?? 0);

            return ['result' => "Cette action porte sur {$taille} blocs, ce qui dépasse le seuil "
                .'autorisé sans accord explicite. Demande confirmation à l’utilisateur, puis '
                .'relance le même appel en ajoutant `confirmed: true`. Aucune modification n’a été '
                .'appliquée pour l’instant.'];
        }

        $message = (string) $resultat['summary'];

        if ($resultat['renumbered']) {
            $message .= ' La numérotation des figures, tableaux et renvois a été mise à jour.';
        }

        if (($resultat['snapshot_id'] ?? null) !== null) {
            // L'utilisateur doit savoir qu'une annulation est disponible : c'est
            // ce qui rend l'action destructive acceptable.
            $message .= ' Cette action peut être annulée (état antérieur enregistré).';
        }

        return ['result' => $message];
    }

    /**
     * Fallback : si l'outil interne (PHPWord/LibreOffice) échoue, délègue
     * à Claude Skills (docx/pdf) quand l'utilisateur est éligible.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result?: string, error?: string}
     */
    private function claudeFallback(string $toolName, array $arguments, string $internalError, ?User $user): array
    {
        if ($user === null || $this->claudeSkills === null) {
            return ['error' => $internalError.' — Options manuelles : ouvrez le document dans Word '
                .'(Fichier → Ouvrir) puis utilisez les outils de mise en forme (police, titres, '
                .'couleurs) ou Enregistrer sous pour la conversion.'];
        }

        try {
            // Construit un prompt Claude à partir de la demande d'origine
            $sourcePath = (string) ($arguments['source_path'] ?? '');
            $operation = (string) ($arguments['operation'] ?? $toolName);
            $outputName = (string) ($arguments['output_filename'] ?? 'document_'.$operation);

            $prompt = $this->claudeFallbackPrompt($toolName, $arguments);
            $skill = $toolName === 'document_to_docx' ? 'pdf' : 'docx';

            Log::info('Chat : fallback Claude pour outil interne en échec', [
                'tool' => $toolName,
                'user_id' => $user->id,
                'error' => $internalError,
            ]);

            $result = $this->claudeSkills->generate($user, $skill, $prompt, $outputName);

            return ['result' => 'Outils internes indisponibles : document généré via Claude Skills — '
                .$result['path'].' ('.$result['cost_credits'].' crédits).'];
        } catch (\Throwable $e) {
            Log::warning('Chat : fallback Claude en échec', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $internalError.' — Options manuelles : ouvrez le document dans Word '
                .'(Fichier → Ouvrir) puis utilisez les outils de mise en forme (police, titres, '
                .'couleurs) ou Enregistrer sous pour la conversion.'];
        }
    }

    /**
     * Construit un prompt Claude à partir des arguments de l'outil.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function claudeFallbackPrompt(string $toolName, array $arguments): string
    {
        $sourcePath = (string) ($arguments['source_path'] ?? '');
        $operation = (string) ($arguments['operation'] ?? '');
        $content = '';

        // Extrait le texte de la pièce jointe pour le donner à Claude
        if ($sourcePath !== '' && $this->textExtraction !== null) {
            $absolutePath = Storage::disk('local')->path($sourcePath);
            if (is_file($absolutePath)) {
                try {
                    $content = $this->textExtraction->execute($absolutePath);
                } catch (\Throwable) {
                    $content = '';
                }
            }
        }

        $promptParts = ["Fichier source : {$sourcePath}"];

        if ($operation === 'replace_text') {
            $promptParts[] = 'Opération : remplacer le texte « '.$arguments['search'] ?? ''
                .' » par « '.$arguments['replacement'] ?? ''.' ».';
        } elseif ($operation === 'edit_title') {
            $promptParts[] = 'Opération : modifier le titre n° '.($arguments['title_number'] ?? '?')
                .' — nouveau texte : '.$arguments['new_text'] ?? '';
        } elseif ($operation === 'append_text') {
            $promptParts[] = 'Opération : ajouter le paragraphe : '.$arguments['text'] ?? '';
        } elseif ($operation === 'change_title_level') {
            $promptParts[] = 'Opération : changer le titre n° '.($arguments['title_number'] ?? '?')
                .' au niveau '.($arguments['new_level'] ?? '?');
        } elseif ($operation === 'change_title_color') {
            $promptParts[] = 'Opération : changer la couleur du titre n° '.($arguments['title_number'] ?? '?')
                .' en '.($arguments['color'] ?? '?');
        } elseif ($operation === 'change_font') {
            $promptParts[] = 'Opération : changer la police en '.($arguments['font_name'] ?? '?');
        } elseif ($operation === 'format_complete') {
            $promptParts[] = 'Opération : mise en forme complète (police, taille, interligne, titres) avec les paramètres : '
                .json_encode($arguments, JSON_UNESCAPED_UNICODE);
        } elseif ($toolName === 'document_to_pdf') {
            $promptParts[] = 'Opération : convertir le document en PDF.';
        } elseif ($toolName === 'document_to_docx') {
            $promptParts[] = 'Opération : convertir le PDF en DOCX éditable.';
        }

        if ($content !== '') {
            $promptParts[] = "Contenu du document :\n".mb_substr($content, 0, 12000);
        }

        return implode("\n\n", $promptParts);
    }

    /**
     * Analyse la structure d'une pièce jointe DOCX via DocAnalyzer (règles
     * Word + regex, ou IA selon la méthode demandée).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, error?: string}
     */
    private function analyzeDocument(array $arguments): array
    {
        $sourcePath = (string) ($arguments['source_path'] ?? '');
        $method = (string) ($arguments['method'] ?? 'regex');

        Log::info('Chat : document_analyze appelé', [
            'source_path' => $sourcePath,
            'method' => $method,
            'arguments' => $arguments,
        ]);

        if ($sourcePath === '') {
            return ['error' => 'Argument source_path manquant (chemin de la pièce jointe à analyser).'];
        }

        if (! str_starts_with($sourcePath, 'chat/attachments/')
            && ! str_starts_with($sourcePath, 'chat/generated/')) {
            return ['error' => 'Chemin source invalide : seules les pièces jointes du chat '
                .'ou les fichiers générés peuvent être analysés.'];
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($sourcePath)) {
            return ['error' => 'Pièce jointe introuvable : '.$sourcePath];
        }

        $absolutePath = $disk->path($sourcePath);
        $titleMethod = $method === 'ia' ? 'ia' : 'regex';

        try {
            $analyzer = new DocAnalyzer(config_path('analyzer.php'));
            $analysis = $analyzer->analyze($absolutePath, titleMethod: $titleMethod);

            // Résumé lisible par l'IA
            $summary = "Structure détectée (méthode : {$titleMethod}) :\n";
            foreach (['titres', 'sous_titres'] as $category) {
                $items = $analysis[$category] ?? [];
                if (count($items) > 0) {
                    $summary .= strtoupper($category).' ('.count($items).") :\n";
                    foreach (array_slice($items, 0, 30) as $item) {
                        $summary .= '  - '.((string) ($item['texte'] ?? ''))."\n";
                    }
                    if (count($items) > 30) {
                        $summary .= '  … et '.(count($items) - 30)." autres\n";
                    }
                }
            }
            foreach (['en_tetes', 'pieds_de_page', 'tableaux', 'images', 'elements_flottants'] as $category) {
                $summary .= strtoupper($category).' : '.count($analysis[$category] ?? [])."\n";
            }

            return ['result' => $summary];
        } catch (\Throwable $e) {
            Log::warning('Échec d\'analyse de document dans le chat', [
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Impossible d\'analyser la structure : '.$e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------
     |  Outils internes
     | ------------------------------------------------------------------ */

    /**
     * Reconstruit un document DOCX complet.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string}
     */
    private function reconstructDocument(array $arguments): array
    {
        $structure = (array) ($arguments['structure'] ?? []);
        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'document_reconstruit'),
            'docx'
        );

        $reconstructor = new DocumentReconstructor;
        $path = $this->storagePath($filename);
        $this->ensureDirectory($path);
        $reconstructor->reconstruct($structure, Storage::disk('local')->path($path));

        return ['result' => 'Document reconstruit : '.$path];
    }

    /**
     * Applique des corrections de structure.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string}
     */
    private function correctStructure(array $arguments): array
    {
        $structure = (array) ($arguments['structure'] ?? []);
        $corrections = (array) ($arguments['corrections'] ?? []);

        $corrected = app(StructureCorrectionService::class)
            ->apply($structure, $corrections);

        return ['result' => 'Structure corrigée : '.json_encode($corrected, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * Génère un DOCX contenant un sommaire (champ TOC PhpWord).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string}
     */
    private function generateTableOfContents(array $arguments): array
    {
        $title = (string) ($arguments['title'] ?? 'SOMMAIRE');
        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'sommaire'),
            'docx'
        );

        $phpWord = new PhpWord;
        $phpWord->getSettings()->setUpdateFields(true);
        $section = $phpWord->addSection();

        $section->addText($title !== '' ? $title : 'SOMMAIRE', ['bold' => true, 'size' => 16]);
        // Champ TOC natif (niveaux 1-3), mis à jour à l'ouverture du document
        $section->addTOC(null, null, 1, 3);

        $path = $this->storagePath($filename);
        $this->ensureDirectory($path);
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path($path));

        return ['result' => 'Sommaire généré : '.$path];
    }

    /* ------------------------------------------------------------------
     |  Outils externes
     | ------------------------------------------------------------------ */

    /**
     * Recherche web via OpenRouter (web_search_options).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string}
     */
    private function webSearch(array $arguments, string $planSlug): array
    {
        $query = (string) ($arguments['query'] ?? '');
        if ($query === '') {
            return ['error' => 'Requête de recherche vide.'];
        }

        $response = $this->openRouter->chat(
            'web_search',
            [
                ['role' => 'user', 'content' => $query],
            ],
            $planSlug,
            [
                'web_search_options' => ['search_context_size' => 'high'],
            ],
        );

        $content = (string) ($response['content'] ?? '');
        $citations = $response['raw']['citations'] ?? [];

        $result = $content;
        if (! empty($citations)) {
            $result .= "\n\nSources :\n";
            foreach ($citations as $i => $citation) {
                $result .= ($i + 1).'. '.($citation['title'] ?? 'Source')
                    .' — '.($citation['url'] ?? '');
            }
        }

        return ['result' => $result];
    }

    /**
     * Génération d'image via OpenRouter (gpt-image-*).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string}
     */
    private function generateImage(array $arguments, string $planSlug): array
    {
        $prompt = (string) ($arguments['prompt'] ?? '');
        if ($prompt === '') {
            return ['error' => 'Description de l\'image vide.'];
        }

        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'image_generee'),
            'png'
        );

        $response = $this->openRouter->chat(
            'image_generation',
            [
                ['role' => 'user', 'content' => $prompt],
            ],
            $planSlug,
            [
                'response_format' => ['type' => 'image'],
                'n' => 1,
            ],
        );

        // Le binaire de l'image peut arriver en base64 dans le contenu
        $content = (string) ($response['content'] ?? '');
        $imageData = null;

        if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $content, $m)) {
            $imageData = base64_decode($m[2], true);
            $extension = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        } else {
            // Fallback : le binaire brut peut être dans content
            $imageData = $content;
            $extension = 'png';
        }

        if ($imageData === null || $imageData === '') {
            return ['error' => 'Aucune image retournée par le modèle.'];
        }

        $path = $this->storagePath($filename, $extension);
        Storage::disk('local')->put($path, $imageData);

        return ['result' => 'Image générée : '.$path.' ('.strlen($imageData).' octets)'];
    }

    /**
     * Génère un document Word (.docx) ou PDF à partir d'un contenu Markdown
     * simple (# titres, - listes, paragraphes).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, error?: string}
     */
    private function createDocument(array $arguments): array
    {
        $content = (string) ($arguments['content'] ?? '');
        if (trim($content) === '') {
            return ['error' => 'Contenu vide : rien à générer.'];
        }

        $format = strtolower((string) ($arguments['format'] ?? 'word'));
        if (! in_array($format, ['word', 'pdf'], true)) {
            return ['error' => 'Format inconnu : '.$format.' (word ou pdf uniquement).'];
        }

        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'document_genere'),
            $format === 'pdf' ? 'pdf' : 'docx'
        );

        $phpWord = new PhpWord;
        $phpWord->getSettings()->setUpdateFields(true);

        // Styles de titre indispensables : sans addTitleStyle, le writer ne
        // produit pas de styleName HeadingN valide → le reader ne reconnaît
        // plus les titres à la relecture (ils deviennent des TextRun).
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 18]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 15]);
        $phpWord->addTitleStyle(3, ['bold' => true, 'size' => 13]);

        $section = $phpWord->addSection();

        // Parse le Markdown simple : # titre, ## sous-titre, - liste, texte
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m)) {
                $level = substr_count($line, '#');
                $titleStyle = match ($level) {
                    1 => ['bold' => true, 'size' => 18],
                    2 => ['bold' => true, 'size' => 15],
                    default => ['bold' => true, 'size' => 13],
                };
                $section->addTitle($m[1], $level);
            } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
                $section->addListItem($m[1], 0);
            } else {
                $section->addText($line);
            }
        }

        // Chemin de sortie : on construit le nom avec extension UNE seule fois
        // (storagePath() re-ajoute l'extension → on passe le nom sans extension).
        $path = $this->storagePath($filename, $format === 'pdf' ? 'pdf' : 'docx');
        $this->ensureDirectory($path);

        if ($format === 'pdf') {
            // Rend le PDF via PhpWord + dompdf
            Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
            Settings::setPdfRendererPath(base_path('vendor/dompdf/dompdf'));
            $writer = IOFactory::createWriter($phpWord, 'PDF');
            $writer->save(Storage::disk('local')->path($path));
        } else {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save(Storage::disk('local')->path($path));
        }

        return ['result' => 'Document '.strtoupper($format === 'pdf' ? 'PDF' : 'DOCX').' généré : '.$path];
    }

    /* ------------------------------------------------------------------
     |  Utilitaires
     | ------------------------------------------------------------------ */

    /**
     * Décode les arguments JSON d'un tool_call.
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Chemin de stockage relatif (disk 'local') avec nom nettoyé.
     */
    private function storagePath(string $filename, string $extension = ''): string
    {
        $base = 'chat/generated/'.date('Y/m/d');
        $name = $this->safeFilename($filename, $extension ?: pathinfo($filename, PATHINFO_EXTENSION));

        return $base.'/'.$name;
    }

    /**
     * Garantit que le répertoire parent d'un chemin existe (PHPWord exige
     * un dossier présent avant d'écrire le fichier).
     */
    private function ensureDirectory(string $path): void
    {
        $directory = \dirname(Storage::disk('local')->path($path));
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    /**
     * Nettoie un nom de fichier (supprime caractères dangereux, limite longueur).
     * Évite la double extension (doc_test.docx.docx) si le nom porte déjà
     * l'extension demandée.
     */
    private function safeFilename(string $name, string $extension): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'fichier';
        $name = trim($name, '._-');
        $name = $name !== '' ? $name : 'fichier';
        $name = substr($name, 0, 80);
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'docx';

        if (! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return $name;
    }
}
