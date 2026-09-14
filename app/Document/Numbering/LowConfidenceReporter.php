<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Structure\StructuralDocument;

/**
 * Signalement discret des résolutions incertaines (phase R4.9).
 *
 * **Principe non négociable de la refonte** : aucun renvoi croisé ne doit
 * déclencher de formulaire ni bloquer l'utilisateur. C'est un **critère
 * d'acceptation explicite de R4** (vérifié par
 * `CrossReferenceDetectorTest::test_aucun_renvoi_ambigu_ne_declenche_de_formulaire`).
 *
 * La raison est une question de proportion : un document de 80 pages peut
 * contenir 40 renvois. Demander confirmation pour chacun rendrait le service
 * inutilisable, alors que la renumérotation est justement censée *éviter* le
 * travail manuel. Un rapport lisible en fin de traitement suffit : l'utilisateur
 * vérifie ce qu'il veut, quand il veut.
 *
 * Ce composant produit donc **un message unique et lisible**, pas une question.
 */
final class LowConfidenceReporter
{
    /**
     * Nombre maximal de renvois détaillés dans le rapport.
     *
     * Au-delà, la liste devient illisible et l'utilisateur ne la lit plus. Le
     * compte total reste exact — seule l'énumération est bornée.
     */
    public const MAX_DETAILED = 5;

    /**
     * Construit le rapport de fin de traitement.
     *
     * @param  StructuralDocument  $document  Document après renumérotation
     * @param  array<int, array{block_id: string, matched_text: string, confidence: float}>  $lowConfidence
     * @param  array<string, mixed>  $extra  Données complémentaires (compteurs, anomalies)
     * @return array{
     *     has_warnings: bool,
     *     message: null|string,
     *     count: int,
     *     details: array<int, array{block_id: string, matched_text: string, confidence: float}>,
     *     truncated: int,
     *     unresolved: int,
     *     blocking: false
     * }
     */
    public function report(StructuralDocument $document, array $lowConfidence = [], array $extra = []): array
    {
        $renvoisIncertains = $lowConfidence === []
            ? $this->lowConfidenceFrom($document)
            : $lowConfidence;

        $nonResolus = count($document->unresolvedCrossRefs());
        $nombre = count($renvoisIncertains);

        return [
            'has_warnings' => $nombre > 0 || $nonResolus > 0,
            'message' => $this->message($nombre, $nonResolus),
            'count' => $nombre,
            'details' => array_slice($renvoisIncertains, 0, self::MAX_DETAILED),
            'truncated' => max(0, $nombre - self::MAX_DETAILED),
            'unresolved' => $nonResolus,
            // Marqueur explicite : ce rapport n'exige AUCUNE action bloquante.
            // L'écrire dans le résultat rend la garantie vérifiable par test.
            'blocking' => false,
        ];
    }

    /**
     * Message lisible, au singulier ou au pluriel.
     *
     * Formulation volontairement non alarmiste : « à vérifier », pas « erreur ».
     * Une résolution par proximité est une estimation raisonnable, pas une faute.
     */
    private function message(int $incertains, int $nonResolus): ?string
    {
        $parties = [];

        if ($incertains > 0) {
            $parties[] = $incertains === 1
                ? '1 renvoi résolu par proximité — à vérifier'
                : $incertains.' renvois résolus par proximité — à vérifier';
        }

        if ($nonResolus > 0) {
            $parties[] = $nonResolus === 1
                ? '1 renvoi sans cible identifiée'
                : $nonResolus.' renvois sans cible identifiée';
        }

        if ($parties === []) {
            return null;
        }

        return implode(' · ', $parties).'.';
    }

    /**
     * Renvois à confiance faible déjà présents dans le document.
     *
     * Permet d'utiliser le rapporteur seul, sans passer la liste en paramètre.
     *
     * @return array<int, array{block_id: string, matched_text: string, confidence: float}>
     */
    private function lowConfidenceFrom(StructuralDocument $document): array
    {
        $renvois = [];

        foreach ($document->lowConfidenceCrossRefs() as $block) {
            $crossRef = $block->crossRef;

            if ($crossRef === null) {
                continue;
            }

            $renvois[] = [
                'block_id' => $block->blockId,
                'matched_text' => $crossRef->matchedText,
                'confidence' => (float) $crossRef->resolutionConfidence,
            ];
        }

        return $renvois;
    }

    /**
     * Résumé textuel de la renumérotation, pour l'affichage.
     *
     * @param  array<string, int>  $perCategory  Nombre d'éléments par catégorie
     * @param  array{duplicates?: array<string, array<int, string>>, gaps?: array<string, array<int, int>>}  $anomalies
     */
    public function numberingSummary(array $perCategory, array $anomalies = []): ?string
    {
        $parties = [];

        foreach ($perCategory as $categorie => $nombre) {
            $parties[] = $nombre.' '.$this->plural($categorie, $nombre);
        }

        if ($parties === []) {
            return null;
        }

        $resume = 'Renumérotation : '.implode(', ', $parties).'.';

        $doublons = $anomalies['duplicates'] ?? [];

        if ($doublons !== []) {
            $total = array_sum(array_map('count', $doublons));
            $resume .= ' '.$total.' numéro'.($total > 1 ? 's' : '').' dupliqué'
                .($total > 1 ? 's' : '').' dans le document d\'origine, corrigé'
                .($total > 1 ? 's' : '').'.';
        }

        return $resume;
    }

    /**
     * Libellé d'une catégorie, au singulier ou au pluriel.
     *
     * Les formes sont écrites explicitement : un pluriel mécanique (ajouter un
     * « x ») produirait « figurex » au lieu de « figures ».
     */
    private function plural(string $categorie, int $nombre): string
    {
        $singulier = match ($categorie) {
            'figure' => 'figure',
            'table' => 'tableau',
            'annexe' => 'annexe',
            'planche' => 'planche',
            default => $categorie,
        };

        if ($nombre <= 1) {
            return $singulier;
        }

        return match ($categorie) {
            'figure' => 'figures',
            'table' => 'tableaux',
            'annexe' => 'annexes',
            'planche' => 'planches',
            default => $singulier.'s',
        };
    }
}
