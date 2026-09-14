<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Classification\CaptionPattern;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;

/**
 * Détection et résolution des renvois croisés (phase R4.4–R4.7).
 *
 * Un renvoi croisé est un renvoi **dans le texte** du style « voir Figure 3 »,
 * « cf. Annexe B », « le Tableau 2 montre ». Il doit pointer vers le bon
 * élément après renumérotation, sinon le document devient faux — c'est l'erreur
 * la plus visible pour un lecteur et la plus grave pour un mémoire.
 *
 * **Décision d'implémentation assumée** — le plan prévoit de *créer des blocs
 * `cross_ref`*. Ce composant ne le fait **pas** : il attache le renvoi au
 * paragraphe qui le contient (`Block::$crossRef`). Créer un bloc séparé pour un
 * renvoi *en ligne* ferait **apparaître le texte deux fois** au rendu (une fois
 * dans le paragraphe, une fois dans le nouveau bloc). Le type `cross_ref` reste
 * disponible pour les blocs que les tools d'édition de R6 créeront de toutes
 * pièces. `StructuralDocument::unresolvedCrossRefs()` a été rendu sensible à la
 * propriété pour couvrir les deux cas.
 *
 * **Résolution en deux temps :**
 *
 *  1. **Numéro unique** → confiance 1,0. Si un seul élément de la catégorie
 *     portait ce numéro d'origine, la cible est certaine.
 *  2. **Numéro ambigu** (doublon, ou numéro disparu après renumérotation) →
 *     résolution par **proximité dans l'ordre du document**, jamais par distance
 *     en caractères. Deux renvois peuvent désigner des numéros identiques et
 *     pointer vers des éléments différents selon l'endroit où ils apparaissent.
 *
 * **Un renvoi ambigu ne bloque jamais l'utilisateur.** Il est résolu au mieux
 * et signalé dans le rapport discret de fin de traitement — jamais par un
 * formulaire (critère d'acceptation explicite de R4).
 */
final class CrossReferenceDetector
{
    /**
     * Confiance attribuée à une résolution non ambiguë (numéro unique).
     */
    public const CONFIDENCE_UNIQUE = 1.0;

    /**
     * Confiance attribuée à une résolution par proximité retenue.
     */
    public const CONFIDENCE_PROXIMITY = 0.75;

    /**
     * Confiance attribuée à une résolution ambiguë (numéro introuvable, ou
     * plusieurs candidats équidistants).
     *
     * Volontairement sous `CrossRef::LOW_CONFIDENCE_THRESHOLD` (0,7) : le renvoi
     * est résolu — pour ne pas laisser une référence fausse dans le document —
     * mais il est **toujours** signalé, car le numéro cité ne confirme rien.
     * C'est la distinction entre « probable » et « vérifiable ».
     */
    public const CONFIDENCE_AMBIGUOUS = 0.55;

    public function __construct(
        private readonly CaptionPattern $captions = new CaptionPattern,
    ) {}

    /**
     * Détecte les renvois croisés d'un document et les résout.
     *
     * Les renvois sont attachés aux paragraphes qui les contiennent. Les
     * légendes sont exclues : une légende « Figure 3 » ne se renvoie pas à
     * elle-même.
     *
     * @return array{
     *     document: StructuralDocument,
     *     detected: int,
     *     resolved: int,
     *     unresolved: int,
     *     low_confidence: array<int, array{block_id: string, matched_text: string, confidence: float}>
     * }
     */
    public function detect(StructuralDocument $document): array
    {
        // Index des éléments numérotables par catégorie :
        //   - par numéro d'origine (pour la résolution directe) ;
        //   - par numéro affichable (calculé s'il existe, sinon l'original).
        //
        // `displayNumber()` et non `finalNumber` : la détection doit fonctionner
        // même si la renumérotation n'a pas tourné (mode legacy, ou test unitaire
        // isolé), sinon aucun renvoi ne serait résolu avant R4.
        $parNumeroOriginal = [];
        $parNumeroFinal = [];

        foreach ($document->blocks as $block) {
            $category = $this->numberingCategoryOf($block);

            if ($category === null) {
                continue;
            }

            if ($block->originalNumber !== null) {
                $parNumeroOriginal[$category->value][$block->originalNumber][] = $block->blockId;
            }

            $affiche = $block->displayNumber();

            if ($affiche !== null) {
                $parNumeroFinal[$category->value][$affiche][] = $block->blockId;
            }
        }

        $detected = 0;
        $resolved = 0;
        $unresolved = 0;
        $faibleConfiance = [];
        $updates = [];

        foreach ($document->blocks as $block) {
            if (! $this->canContainCrossRef($block)) {
                continue;
            }

            $renvois = $this->captions->detectCrossReferences($block->text);

            if ($renvois === []) {
                continue;
            }

            $detected += count($renvois);

            // Un paragraphe peut contenir plusieurs renvois ; on retient le
            // premier résolu (le bloc ne porte qu'un seul `crossRef`).
            foreach ($renvois as $renvoi) {
                $crossRef = new CrossRef(
                    matchedText: $renvoi['matched'],
                    targetCategory: $renvoi['category'],
                    targetOriginalNumber: $renvoi['original_number'],
                );

                $resolution = $this->resolve(
                    $crossRef,
                    $block,
                    $document,
                    $parNumeroOriginal,
                    $parNumeroFinal,
                );

                if ($resolution === null) {
                    // Non résolu : le bloc est mis à jour pour que le rapport
                    // puisse le signaler, sans jamais bloquer.
                    $updates[] = $block->withCrossRef($crossRef);
                    $unresolved++;

                    continue;
                }

                $updates[] = $block->withCrossRef($resolution);
                $resolved++;

                if ($resolution->isLowConfidence()) {
                    $faibleConfiance[] = [
                        'block_id' => $block->blockId,
                        'matched_text' => $resolution->matchedText,
                        'confidence' => (float) $resolution->resolutionConfidence,
                    ];
                }

                // Un seul renvoi par bloc : on passe au bloc suivant.
                break;
            }
        }

        return [
            'document' => $updates === [] ? $document : $document->replaceBlocks($updates),
            'detected' => $detected,
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'low_confidence' => $faibleConfiance,
        ];
    }

    /**
     * Résout un renvoi : numéro unique d'abord, proximité ensuite.
     *
     * @param  array<string, array<string, array<int, string>>>  $parNumeroOriginal
     * @param  array<string, array<string, array<int, string>>>  $parNumeroFinal
     */
    private function resolve(
        CrossRef $crossRef,
        Block $source,
        StructuralDocument $document,
        array $parNumeroOriginal,
        array $parNumeroFinal,
    ): ?CrossRef {
        $categorie = $crossRef->targetCategory->value;
        $numero = $crossRef->targetOriginalNumber;

        // --- Cas 1 : un seul élément portait ce numéro d'origine ---
        $candidats = $parNumeroOriginal[$categorie][$numero] ?? [];

        if (count($candidats) === 1) {
            return $crossRef->resolveTo($candidats[0]);
        }

        // --- Cas 2 : numéro ambigu (plusieurs porteurs) → proximité ---
        if ($candidats !== []) {
            $proche = $this->nearestTo($source->blockId, $candidats, $document);

            if ($proche === null) {
                return null;
            }

            // Plusieurs candidats équidistants : la proximité ne tranche pas,
            // la confiance doit donc passer sous le seuil de signalement.
            $confiance = $this->derniereResolutionAmbiguë
                ? self::CONFIDENCE_AMBIGUOUS
                : self::CONFIDENCE_PROXIMITY;

            return $crossRef->resolveWithLowConfidence($proche, $confiance);
        }

        // --- Cas 3 : numéro introuvable (trou mesuré : 3 documents) ---
        // Le numéro cité n'existe nulle part. On élargit à tous les éléments de
        // la catégorie et la proximité propose une cible — mais le signal est
        // nettement plus faible (le numéro ne confirme rien), donc la confiance
        // est basse par construction et le renvoi sera **toujours** signalé.
        // Le résoudre vaut mieux que le laisser : un renvoi manifestement faux
        // resterait sinon dans le document.
        $candidats = $this->proximityCandidates($parNumeroFinal[$categorie] ?? []);
        $proche = $this->nearestTo($source->blockId, $candidats, $document);

        if ($proche === null) {
            return null;
        }

        return $crossRef->resolveWithLowConfidence($proche, self::CONFIDENCE_AMBIGUOUS);
    }

    /**
     * Ensemble des cibles possibles quand le numéro cité n'existe pas.
     *
     * On élargit à **tous** les éléments de la catégorie : c'est la seule
     * information disponible, et un renvoi résolu-puis-signalé vaut mieux qu'un
     * renvoi abandonné qui laisserait une référence fausse dans le document.
     *
     * @param  array<string, array<int, string>>  $parFinal  Candidats par numéro calculé
     * @return array<int, string>
     */
    private function proximityCandidates(array $parFinal): array
    {
        $tous = [];

        foreach ($parFinal as $identifiants) {
            foreach ($identifiants as $identifiant) {
                $tous[] = $identifiant;
            }
        }

        return array_values(array_unique($tous));
    }

    /**
     * Cible la plus proche du renvoi dans l'ordre du document.
     *
     * **Proximité par INDEX, jamais par distance en caractères** (spec §10) :
     * deux renvois au même numéro doivent pouvoir pointer vers des éléments
     * différents selon leur emplacement dans le texte.
     *
     * Une seule cible proche donne une confiance haute ; plusieurs cibles
     * équidistantes donnent la confiance de proximité basse, ce qui fait passer
     * le renvoi sous le seuil de signalement. On résout quand même — refuser de
     * résoudre laisserait un renvoi manifestement erroné dans le document.
     *
     * @param  array<int, string>  $candidats
     */
    private function nearestTo(string $sourceId, array $candidats, StructuralDocument $document): ?string
    {
        $positionSource = $document->indexOf($sourceId);

        if ($positionSource === null) {
            return null;
        }

        $avecDistance = [];

        foreach ($candidats as $candidatId) {
            $position = $document->indexOf($candidatId);

            if ($position === null) {
                continue;
            }

            $avecDistance[$candidatId] = abs($position - $positionSource);
        }

        if ($avecDistance === []) {
            return null;
        }

        asort($avecDistance);
        $meilleureDistance = (int) reset($avecDistance);
        $meilleurs = array_keys(array_filter(
            $avecDistance,
            static fn (int $distance): bool => $distance === $meilleureDistance
        ));

        // Strictement un seul candidat à cette distance : on mémorise la
        // confiance haute pour que le signalement ne se déclenche pas à tort.
        $this->derniereResolutionAmbiguë = count($meilleurs) > 1;

        return (string) $meilleurs[0];
    }

    /**
     * Le dernier appel à `nearestTo()` avait-il plusieurs candidats équidistants ?
     */
    private bool $derniereResolutionAmbiguë = false;

    /**
     * Le bloc peut-il contenir un renvoi croisé ?
     *
     * Les légendes sont exclues : une légende « Figure 3 » n'est pas un renvoi à
     * elle-même, et la traiter comme telle créerait une auto-référence.
     */
    private function canContainCrossRef(Block $block): bool
    {
        return match ($block->type) {
            BlockType::Paragraph, BlockType::Heading, BlockType::CrossRef => true,
            default => false,
        };
    }

    /**
     * Catégorie de numérotation d'un bloc cible potentiel.
     *
     * Un renvoi désigne soit un porteur (« la Figure 3 »), soit une légende qui
     * porte le numéro. Les deux sont candidats : c'est ce qui permet de résoudre
     * un renvoi dans un document où le numéro vit uniquement sur la légende.
     */
    private function numberingCategoryOf(Block $block): ?BlockCategory
    {
        if ($block->type->isSelfNumbered()) {
            return $block->type->category();
        }

        if ($block->type === BlockType::Caption) {
            return $block->effectiveCategory();
        }

        return null;
    }
}
