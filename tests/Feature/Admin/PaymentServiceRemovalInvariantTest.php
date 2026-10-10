<?php

namespace Tests\Feature\Admin;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Invariant du retrait du module « services de paiement ».
 *
 * **Pourquoi ce module a été retiré.** La table `payment_services` dupliquait deux
 * mécanismes déjà en place, et l'un des deux était inerte :
 *
 *  - `active` / `visible` doublaient les réglages `payments.gateways.*`, déjà
 *    éditables dans `/admin/settings` sans redéploiement ;
 *  - `service_key` / `service_secret` doublaient `monetbil.services.*`, mais la
 *    SIGNATURE lit la configuration, jamais la table. L'écran affichait donc des
 *    clés, laissait les modifier, et restait sans effet — exactement l'échec
 *    silencieux que la règle `billing.md` interdit.
 *
 * **Pourquoi un invariant plutôt qu'une simple suppression.** Sans lui, réintroduire
 * une table d'identifiants de paiement rouvrirait le défaut sans que personne ne
 * sache qu'il avait été corrigé. Ce fichier échoue bruyamment si le module revient.
 */
class PaymentServiceRemovalInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes du module retiré.
     *
     * @return array<int, string>
     */
    private function removedRoutes(): array
    {
        return [
            'payment-services.index',
            'payment-services.create',
            'payment-services.store',
            'payment-services.update',
            'payment-services.destroy',
        ];
    }

    public function test_aucune_route_du_module_services_de_paiement_n_existe_plus(): void
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
            "Le module « services de paiement » est retiré : ces routes ne doivent pas exister.\n"
            ."Elles dupliquaient les réglages `payments.gateways.*` et portaient des clés que la\n"
            ."signature n'utilisait pas.\nRoutes encore déclarées :\n - ".implode("\n - ", $encorePresentes)
        );
    }

    /**
     * Contrôle de non-régression : la gestion des MOYENS de paiement reste.
     *
     * Ne pas confondre le retrait de la table `payment_services` (identifiants par
     * service) avec les réglages d'activation/masquage, qui restent le seul écran
     * d'exploitation d'un moyen en panne. Ces réglages sont la source de l'état
     * opérationnel depuis le retrait de la table.
     */
    public function test_les_reglages_de_moyens_de_paiement_restent_disponibles(): void
    {
        $this->assertTrue(app('router')->has('admin.settings'));

        // L'état « actif » et « visible » de chaque moyen est lu depuis la config :
        // la clé doit exister, sinon `estActif()` retomberait sur son défaut sans
        // qu'aucun écran ne puisse le modifier.
        foreach (['kpay', 'monetbil', 'offline'] as $passerelle) {
            $this->assertIsBool(
                config('payments.gateways.'.$passerelle.'.actif'),
                "Le réglage `payments.gateways.{$passerelle}.actif` doit exister : c'est la source de l'état opérationnel."
            );
            $this->assertIsBool(
                config('payments.gateways.'.$passerelle.'.visible'),
                "Le réglage `payments.gateways.{$passerelle}.visible` doit exister."
            );
        }
    }

    /**
     * Classes supprimées avec le module.
     *
     * @return array<int, string>
     */
    private function removedClasses(): array
    {
        return [
            'PaymentServiceAdminController',
            'PaymentService',
        ];
    }

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
            "Le module « services de paiement » est retiré : plus aucun code ne doit référencer ses classes.\n"
            ."La source de vérité des identifiants est `monetbil.services.*` (lue par la signature).\n"
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
