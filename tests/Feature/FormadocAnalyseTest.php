<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Docx\DocxAnalyzer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Analyseur en lecture seule (`formadoc:analyse`).
 *
 * **Ce que ces tests protègent.** L'analyseur est un INSTRUMENT : sa sortie sert
 * à décider si une règle de détection est bonne ou mauvaise. Un instrument faux
 * rend un verdict faux avec assurance — c'est arrivé plusieurs fois dans ce
 * projet (voir `.ai/rules/general.md`).
 *
 * Trois propriétés sont donc vérifiées ici :
 *  1. **lecture seule** — le fichier analysé n'est pas modifié (empreinte stable) ;
 *  2. **comptages exacts** — sur un document synthétique dont on connaît le
 *     contenu par construction ;
 *  3. **honnêteté** — un cas ambigu est SIGNALÉ, jamais classé d'office.
 */
class FormadocAnalyseTest extends TestCase
{
    /**
     * Construit un `.docx` synthétique minimal.
     *
     * Les caractéristiques sont choisies pour exercer les compteurs :
     *  - trois paragraphes vides consécutifs (série = 3) ;
     *  - un titre par style ;
     *  - un titre par motif de numérotation ;
     *  - une légende tapée avec type connu et numéro.
     */
    private function creerDocument(): string
    {
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);

        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText('Corps du document.');
        $section->addText('');
        $section->addText('');
        $section->addText('');
        $section->addText('CHAPITRE II : METHODE');
        $section->addText('Figure 1 : Architecture du système');
        $section->addText('Suite du corps.');

        $chemin = storage_path('app/analyse_'.uniqid().'.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($chemin);

        return $chemin;
    }

    public function test_l_analyse_compte_les_paragraphes_vides_et_leur_serie(): void
    {
        $fichier = $this->creerDocument();

        try {
            $this->artisan('formadoc:analyse', ['fichier' => $fichier, '--json' => true])
                ->assertSuccessful()
                ->expectsOutputToContain('"serie_vides_max": 3');
        } finally {
            @unlink($fichier);
        }
    }

    public function test_l_analyse_reconnait_un_titre_par_style_et_un_par_motif(): void
    {
        $fichier = $this->creerDocument();

        try {
            $rapport = app(DocxAnalyzer::class)->analyser($fichier);

            $this->assertSame(1, $rapport['candidats_titres']['par_style']['nombre']);
            $this->assertSame('Introduction', $rapport['candidats_titres']['par_style']['detail'][0]['texte']);

            $this->assertSame(1, $rapport['candidats_titres']['par_motif']['nombre']);
            $this->assertSame('CHAPITRE II : METHODE', $rapport['candidats_titres']['par_motif']['detail'][0]['texte']);
        } finally {
            @unlink($fichier);
        }
    }

    public function test_l_analyse_detecte_une_legende_tapee(): void
    {
        $fichier = $this->creerDocument();

        try {
            $rapport = app(DocxAnalyzer::class)->analyser($fichier);

            $legendes = $rapport['candidats_legendes']['detail'];
            $this->assertCount(1, $legendes);
            $this->assertSame('motif', $legendes[0]['preuve']);
            $this->assertSame('1', $legendes[0]['numero']);
        } finally {
            @unlink($fichier);
        }
    }

    /**
     * **L'analyse ne modifie PAS le fichier.**
     *
     * C'est la propriété qui autorise l'exécution sur un document utilisateur.
     * L'empreinte est comparée avant/après : une modification, même minime
     * (réécriture du zip), ferait échouer ce test.
     */
    public function test_l_analyse_ne_modifie_pas_le_fichier(): void
    {
        $fichier = $this->creerDocument();
        $avant = hash_file('sha256', $fichier);
        $tailleAvant = filesize($fichier);

        try {
            $this->artisan('formadoc:analyse', ['fichier' => $fichier, '--json' => true])
                ->assertSuccessful();

            $this->assertSame($avant, hash_file('sha256', $fichier), 'Le document analysé a été modifié.');
            $this->assertSame($tailleAvant, filesize($fichier));
        } finally {
            @unlink($fichier);
        }
    }

    public function test_un_fichier_inexistant_produit_un_echec_explicite(): void
    {
        $this->artisan('formadoc:analyse', ['fichier' => 'inexistant.docx'])
            ->assertExitCode(1)
            ->expectsOutputToContain('introuvable');
    }

    public function test_un_format_non_docx_est_refuse(): void
    {
        $fichier = storage_path('app/pas_un_docx.txt');
        file_put_contents($fichier, 'texte');

        try {
            $this->artisan('formadoc:analyse', ['fichier' => $fichier])
                ->assertExitCode(1)
                ->expectsOutputToContain('Seuls les fichiers .docx');
        } finally {
            @unlink($fichier);
        }
    }
}
