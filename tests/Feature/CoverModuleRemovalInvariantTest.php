<?php

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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

    /**
     * Classes supprimées avec le module.
     *
     * @return array<int, string>
     */
    private function removedClasses(): array
    {
        return [
            'CoverPageTemplateController',
            'CoverGenerationService',
            'CoverDetectionService',
            'CoverPageRenderer',
            'CoverPageTemplate',
            'CoverTemplate',
            'StoreCoverPageTemplateRequest',
            'GenerateCoverRequest',
        ];
    }

    /**
     * Invariant de CODE, complémentaire de l'invariant de ROUTES ci-dessus.
     *
     * Pourquoi il existe : après le retrait, deux relations Eloquent pointaient
     * encore vers `CoverTemplate` et `CoverPageTemplate` — des classes qui
     * n'existent plus. Rien ne le signalait : une relation n'est évaluée qu'au
     * premier appel, donc le code restait vert jusqu'à ce qu'un jour quelqu'un
     * appelle `$generatedDocument->coverTemplate()` et obtienne une erreur
     * fatale. Le test de routes ne voyait pas ce défaut : il ne regarde que le
     * routeur, jamais le code.
     *
     * Les commentaires sont retirés avant l'analyse (voir `stripComments`) :
     * expliquer le retrait, comme le fait ce fichier, n'est pas une référence.
     */
    public function test_aucun_code_ne_reference_une_classe_supprimee(): void
    {
        $references = [];

        foreach ($this->removedClasses() as $classe) {
            $motif = '/(?<![\w$\\\\])'.preg_quote($classe, '/').'(?![\w])/';

            foreach ($this->fichiersPhp(base_path('app')) as $chemin) {
                $code = $this->stripComments(file_get_contents($chemin));

                if (preg_match($motif, $code)) {
                    $references[] = $classe.' → '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $chemin);
                }
            }
        }

        $this->assertSame(
            [],
            $references,
            "Le module « page de garde » est supprimé : plus aucun code ne doit référencer ses classes.\n"
            ."Références trouvées :\n - ".implode("\n - ", $references)
        );
    }

    /**
     * Tous les fichiers PHP sous un répertoire, récursivement.
     *
     * @return array<int, string>
     */
    private function fichiersPhp(string $racine): array
    {
        $fichiers = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $fichiers[] = $file->getPathname();
            }
        }

        return $fichiers;
    }

    /**
     * Retire commentaires et docblocks d'un source PHP.
     *
     * Analyse le CODE réellement exécuté : un commentaire qui explique « cette
     * classe a été supprimée » ne doit pas être compté comme une référence.
     */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
