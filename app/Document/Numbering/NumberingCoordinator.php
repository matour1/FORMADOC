<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Structure\StructuralDocument;

/**
 * Orchestrateur de la phase R4 : cohérence de la numérotation et des renvois.
 *
 * Enchaîne les cinq composants dans le seul ordre qui produit un résultat juste :
 *
 * ```
 *  1. CaptionLinker        rattache chaque légende à son porteur
 *                          (mesure : 861 légendes sans aucun lien — c'est le
 *                          travail manquant que R4 doit accomplir)
 *  2. NumberingPass        renumérote par ordre d'apparition, compteur par
 *                          catégorie, et reflète le numéro sur le porteur
 *  3. CrossReferenceDetector  détecte « voir Figure 3 » et le résout
 *  4. CrossRefRewriter     réécrit le numéro dans le texte du renvoi
 *  5. LowConfidenceReporter   produit le rapport discret (jamais bloquant)
 * ```
 *
 * **Pourquoi cet ordre est le seul correct** — la détection des renvois (3)
 * s'appuie sur les numéros *calculés* : la lancer avant la renumérotation (2)
 * ferait résoudre les renvois sur les numéros d'origine, donc sur des valeurs
 * fausses dans 98 documents mesurés. De même, la réécriture (4) a besoin d'un
 * renvoi déjà résolu (3) pour connaître sa cible.
 *
 * **Coût** : aucun token. Toute la phase est déterministe — c'est précisément
 * ce qui permet de corriger 98 documents à doublons sans facture.
 */
final class NumberingCoordinator
{
    public function __construct(
        private readonly CaptionLinker $linker = new CaptionLinker,
        private readonly NumberingPass $numbering = new NumberingPass,
        private readonly CrossReferenceDetector $detector = new CrossReferenceDetector,
        private readonly CrossRefRewriter $rewriter = new CrossRefRewriter,
        private readonly LowConfidenceReporter $reporter = new LowConfidenceReporter,
    ) {}

    /**
     * Exécute la phase complète sur un document.
     *
     * @return array{
     *     document: StructuralDocument,
     *     linking: array{linked: int, orphans: array<int, string>, low_confidence: array<int, string>},
     *     numbering: array{renumbered: int, propagated: int, per_category: array<string, int>},
     *     references: array{detected: int, resolved: int, unresolved: int},
     *     rewritten: int,
     *     report: array<string, mixed>,
     *     changes: array<int, array<string, mixed>>
     * }
     */
    public function run(StructuralDocument $document): array
    {
        // 1. Rattachement légende ↔ porteur.
        $linking = $this->linker->link($document);
        $document = $linking['document'];

        // 2. Renumérotation (ancre sur ce qui porte le numéro, puis réflexion).
        $numbering = $this->numbering->run($document);
        $document = $numbering['document'];

        // 3. Détection et résolution des renvois, sur les numéros calculés.
        $references = $this->detector->detect($document);
        $document = $references['document'];

        // 4. Réécriture du texte des renvois avec le numéro calculé.
        $rewriting = $this->rewriter->rewrite($document);
        $document = $rewriting['document'];

        // 5. Rapport discret — jamais bloquant.
        $anomalies = $this->numbering->anomalies($document);
        $report = $this->reporter->report($document, $references['low_confidence'], [
            'numbering' => $numbering['per_category'],
            'anomalies' => $anomalies,
        ]);

        return [
            'document' => $document,
            'linking' => [
                'linked' => $linking['linked'],
                'orphans' => $linking['orphans'],
                'low_confidence' => $linking['low_confidence'],
            ],
            'numbering' => [
                'renumbered' => $numbering['renumbered'],
                'propagated' => $numbering['propagated'],
                'per_category' => $numbering['per_category'],
            ],
            'references' => [
                'detected' => $references['detected'],
                'resolved' => $references['resolved'],
                'unresolved' => $references['unresolved'],
            ],
            'rewritten' => $rewriting['rewritten'],
            'report' => $report,
            'changes' => $numbering['changes'],
        ];
    }

    /**
     * Résumé lisible de la phase, pour l'affichage.
     *
     * @param  array<string, mixed>  $result  Résultat de `run()`
     */
    public function summary(array $result): string
    {
        $nombre = $result['numbering']['per_category'] ?? [];
        $references = $result['references'] ?? [];

        $parties = [];

        $resume = $this->reporter->numberingSummary($nombre);
        if ($resume !== null) {
            $parties[] = $resume;
        }

        $resolus = (int) ($references['resolved'] ?? 0);
        if ($resolus > 0) {
            $parties[] = $resolus.' renvoi'.($resolus > 1 ? 's' : '').' croisé'
                .($resolus > 1 ? 's' : '').' résolu'.($resolus > 1 ? 's' : '').'.';
        }

        $message = $result['report']['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $parties[] = $message;
        }

        return implode(' ', $parties);
    }

    /**
     * Le rapport contient-il un signalement à afficher ?
     *
     * @param  array<string, mixed>  $report
     */
    public function hasWarnings(array $report): bool
    {
        return (bool) ($report['has_warnings'] ?? false);
    }
}
