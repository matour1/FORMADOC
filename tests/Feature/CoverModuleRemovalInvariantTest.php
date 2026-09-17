<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant du retrait du module « page de garde ».
 *
 * **Pourquoi ce fichier remplace un test de sécurité au lieu d'être supprimé.**
 * L'ancien `CoverTemplatePreviewSecurityTest` couvrait un durcissement réel
 * (P1-3) : URL signée et expirable, suppression du header `X-Preview-Path` qui
 * exposait le chemin serveur, token restreint au préfixe `preview-`, refus de la
 * traversée de répertoire. Ces protections restent vraies — mais elles portent
 * sur des routes qui n'existent plus dans cette version du produit.
 *
 * Supprimer le fichier effacerait la trace de ce durcissement : un futur
 * rétablissement du module réintroduirait les failles sans que personne ne sache
 * qu'elles avaient été corrigées. On conserve donc un invariant explicite, qui
 * échouera bruyamment si les routes reviennent sans les protections.
 */
class CoverModuleRemovalInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toutes les routes du module « page de garde ».
     *
     * @return array<int, string>
     */
    private function removedRoutes(): array
    {
        return [
            'cover-templates.index',
            'cover-templates.create',
            'cover-templates.store',
            'cover-templates.show',
            'cover-templates.edit',
            'cover-templates.update',
            'cover-templates.destroy',
            'cover-templates.check',
            'cover-templates.preview',
            'cover-templates.preview.file',
            'cover-templates.duplicate',
            'cover-templates.from-example',
            'cover-templates.detect-example',
            'cover-templates.store-from-example',
            'documents.generate-cover',
            'documents.generate-cover-page',
        ];
    }

    public function test_aucune_route_du_module_page_de_garde_n_existe_plus(): void
    {
        $encorePresentes = [];

        foreach ($this->removedRoutes() as $nom) {
            if (app('router')->has($nom)) {
                $encorePresentes[] = $nom;
            }
        }

        $this->assertSame(
            [],
            $encorePresentes,
            "Le module « page de garde » est retiré de cette version : ces routes ne doivent pas exister.\n"
            ."Routes encore déclarées :\n - ".implode("\n - ", $encorePresentes)
        );
    }

    public function test_la_generation_de_document_reste_disponible(): void
    {
        // Contrôle de non-régression : le retrait de `generate-cover` ne doit pas
        // avoir emporté la génération de document, qui est le cœur du produit.
        $this->assertTrue(app('router')->has('documents.generate'));
        $this->assertTrue(app('router')->has('documents.upload'));
        $this->assertTrue(app('router')->has('documents.show'));
        $this->assertTrue(app('router')->has('documents.export'));
    }

    public function test_les_gabarits_de_mise_en_forme_restent_disponibles(): void
    {
        // Ne pas confondre les deux notions : le GABARIT de mise en forme
        // (polices, styles de titres) est le cœur de la version et reste ;
        // seule la PAGE DE GARDE (couverture) est retirée.
        $this->assertTrue(app('router')->has('templates.index'));
        $this->assertTrue(app('router')->has('templates.compare'));
    }
}
