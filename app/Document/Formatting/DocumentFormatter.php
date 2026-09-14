<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\StructuralDocument;

/**
 * Point d'entrée de la mise en forme (phase R3).
 *
 * Assemble les quatre composants déterministes et produit une description de
 * rendu complète. **Aucun token consommé**, aucun appel réseau : c'est une
 * transformation pure, donc reproductible et testable sans infrastructure.
 *
 * Ordre d'application, et pourquoi :
 *  1. `TemplateEngine` — résout les styles de tous les blocs (base de tout) ;
 *  2. `TableRestyler` — calcule le style des tableaux **et leur empreinte**,
 *     ce qui permet de vérifier après coup que rien n'a bougé ;
 *  3. `FigureFlowFormatter` — décide de la solidarité figure/légende ;
 *  4. `HeaderFooterInjector` — en-têtes, pieds et numérotation de pages.
 *
 * Le contenu des blocs n'est jamais réécrit à aucune de ces étapes : le
 * résultat porte les mêmes objets `Block` que l'entrée.
 */
final class DocumentFormatter
{
    public function __construct(
        private readonly TemplateEngine $engine = new TemplateEngine,
        private readonly TableRestyler $tableRestyler = new TableRestyler,
        private readonly FigureFlowFormatter $figureFlow = new FigureFlowFormatter,
        private readonly HeaderFooterInjector $headerFooter = new HeaderFooterInjector,
        private readonly ReconstructionPayloadBuilder $payloadBuilder = new ReconstructionPayloadBuilder,
    ) {}

    /**
     * Applique un gabarit complet à un document structuré.
     *
     * @param  StructuralDocument  $document  Document classifié (sortie de R2)
     * @param  null|array<string, mixed>  $template  Gabarit partiel ou complet
     * @param  array<string, mixed>  $options  Options de rendu (en-tête, pagination…)
     * @return array{
     *     rendered: RenderedDocument,
     *     tables: array<string, array<string, mixed>>,
     *     figures: array<string, mixed>,
     *     header_footer: array<string, mixed>
     * } La mise en forme ne peut pas échouer silencieusement : un gabarit
     *   incomplet est complété par les valeurs par défaut.
     */
    public function format(
        StructuralDocument $document,
        ?array $template = null,
        array $options = [],
    ): array {
        // 1. Styles de tous les blocs.
        $rendered = $this->engine->apply($document, $template);
        $gabarit = $rendered->template;

        // 2. Tableaux : style seul, contenu intact (empreinte pour vérifier).
        $tables = $this->tableRestyler->restyleAll($document->blocks, $gabarit);

        // 3. Figures et légendes : position dans le flux, fichier image inchangé.
        $figures = $this->figureFlow->plan($document->blocks);

        // 4. En-têtes, pieds de page, numérotation.
        $headerFooter = $this->headerFooter->describe($document->blocks, $gabarit, $options);

        // Les métadonnées de rendu sont ajoutées au RenderedDocument pour que
        // le rapport de traitement puisse s'y référer sans recalculer.
        $rendered = $rendered
            ->withMeta('tables', $this->tableRestyler->statistics($document->blocks))
            ->withMeta('figures', $this->figureFlow->statistics($document->blocks))
            ->withMeta('header_footer', $headerFooter)
            ->withMeta('source_fidelity', $rendered->fidelity->value);

        if ($figures['orphan_captions'] !== []) {
            $rendered = $rendered->withMeta('orphan_captions', $figures['orphan_captions']);
        }

        return [
            'rendered' => $rendered,
            'tables' => $tables,
            'figures' => $figures,
            'header_footer' => $headerFooter,
        ];
    }

    /**
     * Construit la charge utile attendue par `DocumentReconstructor` (tâche R3.7).
     *
     * Point d'entrée du branchement : le nouveau pipeline produit la description
     * de rendu, l'ancien générateur écrit le fichier DOCX. Aucun des deux n'a
     * besoin de connaître l'autre, ce qui permet de tester la mise en forme sans
     * écrire de fichier.
     *
     * @param  StructuralDocument  $document  Document classifié
     * @param  null|array<string, mixed>  $template  Gabarit partiel ou complet
     * @param  array<string, mixed>  $options  Options de rendu
     * @param  array<string, array{data: string, extension: string}>  $images  Binaire base64 par référence
     * @return array{analysis: array<string, mixed>, gabarit: array<string, mixed>}
     */
    public function buildReconstructionPayload(
        StructuralDocument $document,
        ?array $template = null,
        array $options = [],
        array $images = [],
    ): array {
        $result = $this->format($document, $template, $options);

        return $this->payloadBuilder->build($result['rendered'], $images);
    }

    /**
     * Contrôle d'intégrité du contenu après mise en forme.
     *
     * Vérifie que **chaque tableau** du document d'origine se retrouve avec une
     * empreinte identique modèles. C'est l'assertion qui protège la donnée
     * chiffrée : si elle échoue, la mise en forme a corrompu un tableau et le
     * rendu doit être refusé.
     *
     * @param  array<int, Block>  $blocks  Blocs d'origine
     * @param  array<string, array<string, mixed>>  $tables  Descriptions issues de `format()`
     * @return array{intact: bool, checked: int, corrupted: array<int, string>}
     */
    public function verifyContentIntegrity(array $blocks, array $tables): array
    {
        $checked = 0;
        $corrupted = [];

        foreach ($blocks as $block) {
            if (! isset($tables[$block->blockId])) {
                continue;
            }

            $checked++;

            if (! $this->tableRestyler->contentIsIntact($block, $tables[$block->blockId])) {
                $corrupted[] = $block->blockId;
            }
        }

        return [
            'intact' => $corrupted === [],
            'checked' => $checked,
            'corrupted' => $corrupted,
        ];
    }
}
