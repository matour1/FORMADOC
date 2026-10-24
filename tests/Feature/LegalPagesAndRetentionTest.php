<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Vérifie la rétention des fichiers à 72 h (H3) et la mention de l'analyseur
 * interne sur les 3 pages légales (G2).
 */
class LegalPagesAndRetentionTest extends TestCase
{
    public function test_la_retention_par_defaut_est_configuree_a_72_heures(): void
    {
        $this->assertSame(72, config('document.retention_hours'));
    }

    public function test_les_trois_pages_legales_affichent_la_retention_de_72_heures_et_la_clause_analyseur(): void
    {
        $cgu = $this->get(route('pages.terms'));
        $cgu->assertOk();
        $cgu->assertSee('72 heures');
        $cgu->assertSee('analyseur interne de FORMADOC');

        $confidentialite = $this->get(route('pages.privacy'));
        $confidentialite->assertOk();
        $confidentialite->assertSee('72 heures');
        $confidentialite->assertSee('étalonnage de l\'analyseur', false);

        $mentions = $this->get(route('pages.legal'));
        $mentions->assertOk();
        $mentions->assertSee('72 heures');
        $mentions->assertSee('mesures techniques et statistiques internes');
    }
}
