<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\RegexTitleDetector;
use Tests\TestCase;

/**
 * Tests du RegexTitleDetector (détection déterministe de titres par motifs).
 *
 * Vérifie :
 *  - numérotation décimale : "1. Introduction" → niveau 1
 *  - sous-numérotation : "1.1 Contexte" → niveau 2 ; "2.3.1 Analyse" → niveau 3
 *  - mots-clés de niveau 1 : CHAPITRE, INTRODUCTION, CONCLUSION…
 *  - légendes exclues : "Figure 1: …" n'est jamais un titre
 *  - parents non-body (header/footer) ignorés
 *  - positions conservées pour la fusion (ResultMerger)
 */
class RegexTitleDetectorTest extends TestCase
{
    private function detect(string ...$lines): array
    {
        return (new RegexTitleDetector)->detect(implode("\n", $lines));
    }

    /** Ligne au format DocumentParser : [POS:section_X,element_Y,parent_Z]TEXTE */
    private function body(int $section, int $element, string $texte): string
    {
        return "[POS:section_{$section},element_{$element},parent_body]{$texte}";
    }

    private function nonBody(int $section, int $element, string $parent, string $texte): string
    {
        return "[POS:section_{$section},element_{$element},parent_{$parent}]{$texte}";
    }

    public function test_numeration_1_niveau(): void
    {
        $result = $this->detect($this->body(0, 2, '1. Introduction'));

        $this->assertCount(1, $result['titres']);
        $this->assertSame('1. Introduction', $result['titres'][0]['texte']);
        $this->assertSame(1, $result['titres'][0]['niveau']);
        $this->assertSame('regex', $result['titres'][0]['source']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_numeration_2_et_3_niveaux(): void
    {
        $result = $this->detect(
            $this->body(0, 0, '1. Introduction'),
            $this->body(0, 1, '1.1 Contexte'),
            $this->body(0, 2, '1.1.1 Détails'),
            $this->body(0, 3, '2.3.1 Analyse statistique'),
        );

        $this->assertCount(1, $result['titres']);
        $this->assertSame('1. Introduction', $result['titres'][0]['texte']);
        $this->assertSame(1, $result['titres'][0]['niveau']);

        $this->assertCount(3, $result['sous_titres']);
        $niveaux = array_column($result['sous_titres'], 'niveau');
        $this->assertContains(2, $niveaux);
        $this->assertContains(3, $niveaux);
    }

    public function test_mots_cles_niveau_1(): void
    {
        $result = $this->detect(
            $this->body(0, 0, 'CHAPITRE 2 : Cadre théorique'),
            $this->body(0, 1, 'Introduction'),
            $this->body(0, 2, 'CONCLUSION'),
            $this->body(0, 3, 'Bibliographie'),
            $this->body(0, 4, 'ANNEXES'),
        );

        $this->assertCount(5, $result['titres']);
        foreach ($result['titres'] as $titre) {
            $this->assertSame(1, $titre['niveau']);
        }
        $textes = array_column($result['titres'], 'texte');
        $this->assertContains('CHAPITRE 2 : Cadre théorique', $textes);
        $this->assertContains('Introduction', $textes);
        $this->assertContains('Bibliographie', $textes);
    }

    public function test_legendes_exclues(): void
    {
        $result = $this->detect(
            $this->body(0, 0, 'Figure 1: Architecture de la plateforme'),
            $this->body(0, 1, 'Tableau 2 : Résultats comparatifs'),
            $this->body(0, 2, 'Figure 3. Schéma du processus'),
            $this->body(0, 3, 'Schéma 4 - Flux de données'),
        );

        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_parents_non_body_ignores(): void
    {
        $result = $this->detect(
            $this->nonBody(0, 0, 'header', '1. Introduction'),
            $this->nonBody(0, 1, 'footer', '1.1 Contexte'),
        );

        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_positions_conservees(): void
    {
        $result = $this->detect($this->body(2, 7, '1. Introduction'));

        $this->assertSame(2, $result['titres'][0]['position']['section_index']);
        $this->assertSame(7, $result['titres'][0]['position']['element_index']);
        $this->assertSame('body', $result['titres'][0]['position']['parent']);
    }

    public function test_lignes_sans_position_ignorees(): void
    {
        $result = $this->detect(
            'Texte brut sans balise POS.',
            '1. Introduction',
            $this->body(0, 0, 'Introduction'),
        );

        $this->assertCount(1, $result['titres']);
        $this->assertSame('Introduction', $result['titres'][0]['texte']);
    }

    public function test_paragraphes_normaux_ignores(): void
    {
        $result = $this->detect(
            $this->body(0, 0, 'Ceci est un paragraphe de contenu sans numérotation.'),
            $this->body(0, 1, 'L\'objectif de ce projet est de concevoir une plateforme.'),
        );

        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    // ── Titres en MAJUSCULES (Phase 3 — défaut 1) ────────────────────────────

    public function test_titre_entierement_en_majuscules_niveau_1(): void
    {
        $result = $this->detect($this->body(0, 4, 'INTRODUCTION'));

        $this->assertCount(1, $result['titres']);
        $this->assertSame('INTRODUCTION', $result['titres'][0]['texte']);
        $this->assertSame(1, $result['titres'][0]['niveau']);
        $this->assertSame('regex', $result['titres'][0]['source']);
    }

    public function test_titre_majuscules_avec_chiffres_et_ponctuation(): void
    {
        $result = $this->detect(
            $this->body(0, 0, '1. CONTEXTE GENERAL'),
            $this->body(0, 1, 'ÉTAT DE L\'ART : ÉTUDE COMPARATIVE'),
            $this->body(0, 2, 'MÉTHODOLOGIE (PARTIE 2) — SYNTHÈSE'),
        );

        $this->assertCount(3, $result['titres']);
        foreach ($result['titres'] as $titre) {
            $this->assertSame(1, $titre['niveau']);
        }
    }

    public function test_phrase_en_majuscules_ignoree(): void
    {
        // Une phrase complète en capitales (ponctuation forte, longue) n'est
        // pas un titre : point final, conjonctions… — trop longue (> 60) ou
        // terminée par un point.
        $result = $this->detect(
            $this->body(0, 0, 'IL FAUT NOTER QUE CE PROJET A ÉTÉ MENÉ DANS UN CONTEXTE PARTICULIER.'),
            $this->body(0, 1, 'ONU.'),
            $this->body(0, 2, 'UN TITRE EN MAJUSCULES QUI EST TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS TRÈS LONG.'),
        );

        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_legende_en_majuscules_exclue(): void
    {
        $result = $this->detect(
            $this->body(0, 0, 'FIGURE 1: ARCHITECTURE DE LA PLATEFORME'),
            $this->body(0, 1, 'TABLEAU 2 : RÉSULTATS COMPARATIFS'),
        );

        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_priorite_mots_cles_et_numeration_sur_majuscules(): void
    {
        // La numérotation décimale garde la priorité : "1.1 Contexte" (avec
        // minuscules) reste un sous-titre niveau 2, pas un titre MAJUSCULES.
        $result = $this->detect(
            $this->body(0, 0, '1. Introduction'),
            $this->body(0, 1, '1.1 Contexte'),
            $this->body(0, 2, 'CONTEXTE GENERAL'),
        );

        // "1. Introduction" → numérotation (niveau 1) ; "CONTEXTE GENERAL" →
        // MAJUSCULES (niveau 1) : deux titres au total.
        $this->assertCount(2, $result['titres']);
        $textes = array_column($result['titres'], 'texte');
        $this->assertContains('1. Introduction', $textes);
        $this->assertContains('CONTEXTE GENERAL', $textes);
        foreach ($result['titres'] as $titre) {
            $this->assertSame(1, $titre['niveau']);
        }

        // "1.1 Contexte" → numérotation 2 segments → sous-titre niveau 2
        $sousTitres = array_column($result['sous_titres'], 'texte');
        $this->assertContains('1.1 Contexte', $sousTitres);
        $this->assertSame(2, $result['sous_titres'][0]['niveau']);
    }
}
