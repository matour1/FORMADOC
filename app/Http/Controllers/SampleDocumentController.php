<?php

namespace App\Http\Controllers;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Document d'exemple (landing page).
 *
 * Génère à la volée un DOCX « avant / après » pour illustrer le rendu de
 * FORMADOC (item P2 de l'audit UI/UX : « Voir un exemple de résultat »).
 */
class SampleDocumentController extends Controller
{
    public function download(): BinaryFileResponse
    {
        $phpWord = new PhpWord;

        // Page 1 — avant mise en forme
        $sectionBefore = $phpWord->addSection();
        $sectionBefore->addText(
            'RAPPORT DE STAGE — version brute',
            ['bold' => true, 'size' => 14, 'color' => '2b3f66']
        );
        $sectionBefore->addText('Introduction');
        $sectionBefore->addText(
            "Ce rapport presente le deroulement de mon stage. J'ai effectue un stage de six semaines dans une entreprise de telecommunication a Douala. Au cours de ce stage, j'ai participe a la maintenance du reseau et a l'installation de nouveaux equipements. Ce document n'est pas encore mis en forme, les titres ne sont pas normalises et la police est incohérente."
        );
        $sectionBefore->addText('1. Presentation de l entreprise');
        $sectionBefore->addText(
            "L'entreprise est situee a Douala, elle compte environ deux cents employes. Le service informatique est charge de la gestion du parc materiel et du reseau local. Les equipes interviennent aussi aupres des clients professionnels pour la mise en place de solutions de connectivite."
        );
        $sectionBefore->addText('1.1 Organisation');
        $sectionBefore->addText(
            "Le service informatique est organise en trois poles : le pole infrastructure, le pole support et le pole developpement. Chaque pole est supervise par un responsable qui rend compte au directeur des systemes d'information."
        );

        // Page 2 — après mise en forme
        $sectionAfter = $phpWord->addSection();
        $sectionAfter->addText(
            'RAPPORT DE STAGE — après FORMADOC',
            ['bold' => true, 'size' => 14, 'color' => '2b3f66']
        );
        $sectionAfter->addText(
            'Introduction',
            ['bold' => true, 'size' => 12, 'color' => '1f2937']
        );
        $sectionAfter->addText(
            'Ce rapport présente le déroulement de mon stage. J\'ai effectué un stage de six semaines dans une entreprise de télécommunication à Douala. Au cours de ce stage, j\'ai participé à la maintenance du réseau et à l\'installation de nouveaux équipements. Ce document est désormais mis en forme : les titres sont normalisés, la police est cohérente et la structure est claire.',
            ['size' => 11]
        );
        $sectionAfter->addText(
            '1. Présentation de l\'entreprise',
            ['bold' => true, 'size' => 12, 'color' => '2b3f66']
        );
        $sectionAfter->addText(
            'L\'entreprise est située à Douala, elle compte environ deux cents employés. Le service informatique est chargé de la gestion du parc matériel et du réseau local. Les équipes interviennent aussi auprès des clients professionnels pour la mise en place de solutions de connectivité.',
            ['size' => 11]
        );
        $sectionAfter->addText(
            '1.1 Organisation',
            ['bold' => true, 'size' => 11.5, 'color' => '374151']
        );
        $sectionAfter->addText(
            'Le service informatique est organisé en trois pôles : le pôle infrastructure, le pôle support et le pôle développement. Chaque pôle est supervisé par un responsable qui rend compte au directeur des systèmes d\'information.',
            ['size' => 11]
        );

        // Flux direct vers le navigateur
        $tempPath = tempnam(sys_get_temp_dir(), 'formadoc_sample_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return response()
            ->download($tempPath, 'formadoc-exemple-avant-apres.docx')
            ->deleteFileAfterSend(true);
    }
}
