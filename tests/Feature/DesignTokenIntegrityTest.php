<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Intégrité des jetons CSS du design system.
 *
 * **Le défaut que ce test rend impossible.**
 *
 * Écrire `var(--color-success)` dans une vue alors que ce jeton n'est défini
 * nulle part ne produit **aucune erreur** : la propriété CSS tombe simplement
 * sur `inherit`, et l'élément s'affiche sans la couleur attendue. Pendant que
 * le design system gagnait sa nouvelle palette, trois jetons étaient dans ce
 * cas — `--color-success`, `--color-danger`, `--color-ink` — utilisés dans
 * **10 endroits sur 6 fichiers**.
 *
 * Conséquence concrète : sur les tableaux d'administration, un badge « Validé »
 * et un badge « Échec » se rendaient **à l'identique**. L'information la plus
 * importante de l'écran (le statut) disparaissait, sans erreur, sans log, et
 * sans que la relecture du code le montre : le nom du jeton, lui, est correct.
 *
 * Seul un rapprochement entre les jetons DÉFINIS et les jetons UTILISÉS révèle
 * ce défaut. C'est exactement ce que fait ce test.
 *
 * **Pourquoi un test et pas une revue.** Le code fautif est parfaitement
 * plausible : rien ne distingue `var(--color-success)` d'un jeton valide quand
 * on lit une vue. Une revue humaine laisse passer ce cas ; un test non.
 */
class DesignTokenIntegrityTest extends TestCase
{
    /**
     * Chemin du design system (source unique des jetons).
     */
    private function cssPath(): string
    {
        return resource_path('css/formadoc.css');
    }

    /**
     * Jetons DÉFINIS dans le design system.
     *
     * @return array<int, string>
     */
    private function definedTokens(): array
    {
        $css = (string) file_get_contents($this->cssPath());

        // Une déclaration de jeton : `--nom: valeur;` en début de ligne indenté.
        preg_match_all('/^\s+(--[a-z][a-z0-9-]*)\s*:/m', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Jetons UTILISÉS dans les vues Blade (`var(--nom)`).
     *
     * @return array<string, array<int, string>> Jeton → fichiers qui l'utilisent
     */
    private function usedTokens(): array
    {
        $usage = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // Les jetons LUS depuis le CSS (var()) sont le seul cas qui casse
            // silencieusement : une var() non résolue n'émet aucune erreur.
            preg_match_all('/var\(\s*(--[a-z][a-z0-9-]*)/', $content, $matches);

            foreach (array_unique($matches[1]) as $token) {
                $usage[$token][] = $file->getFilename();
            }
        }

        return $usage;
    }

    /**
     * Aucun jeton utilisé dans une vue ne doit être absent du design system.
     *
     * C'est LE test qui empêche l'écran de mentir sur ses couleurs : un jeton
     * manquant ne casse pas la page, il la rend muette.
     */
    public function test_tout_jeton_utilise_dans_une_vue_est_defini_dans_le_design_system(): void
    {
        $defined = $this->definedTokens();
        $used = $this->usedTokens();

        $missing = [];

        foreach ($used as $token => $files) {
            if (! in_array($token, $defined, true)) {
                $missing[$token] = array_values(array_unique($files));
            }
        }

        $rapport = '';
        foreach ($missing as $token => $files) {
            $rapport .= sprintf("\n  %s — utilisé par : %s", $token, implode(', ', $files));
        }

        $this->assertSame(
            [],
            array_keys($missing),
            'Des jetons CSS sont utilisés dans les vues mais ne sont définis nulle part.'
            .' Une var() non résolue ne produit AUCUNE erreur : la propriété tombe sur'
            .' « inherit » et l\'élément perd sa couleur sans que rien ne le signale.'
            .' Définir le jeton dans resources/css/formadoc.css (thème clair ET sombre).'.$rapport
        );
    }

    /**
     * Tout jeton de surface doit avoir sa valeur en thème sombre.
     *
     * **Pourquoi.** Un jeton défini seulement dans `:root` garde sa valeur
     * claire sur fond sombre — du texte sombre sur canevas sombre, donc
     * illisible. Le défaut ne se voit pas en thème clair, où tout fonctionne :
     * il survit donc à une vérification visuelle faite en clair.
     */
    public function test_les_jetons_de_surface_ont_une_valeur_en_theme_sombre(): void
    {
        $css = (string) file_get_contents($this->cssPath());

        $this->assertMatchesRegularExpression(
            '/\[data-theme="dark"\]\s*\{/',
            $css,
            'Le bloc de thème sombre est introuvable dans le design system.'
        );

        preg_match('/\[data-theme="dark"\]\s*\{(.*?)\n\}/s', $css, $darkBlock);
        $dark = $darkBlock[1] ?? '';

        // Jetons qui INVENTENT une surface : s'ils ne suivent pas le thème,
        // le contraste s'inverse sans prévenir.
        $critiques = [
            '--color-bg',
            '--color-surface',
            '--color-surface-2',
            '--color-text',
            '--color-border',
        ];

        foreach ($critiques as $token) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($token, '/').'\s*:/',
                $dark,
                sprintf(
                    'Le jeton %s n\'a pas de valeur en thème sombre : il conservera sa'
                    .' valeur claire sur le canevas sombre, ce qui produit du texte'
                    .' illisible (même couleur que le fond).',
                    $token
                )
            );
        }
    }

    /**
     * Un texte posé sur un fond qui s'inverse en thème sombre doit passer par
     * un jeton `--color-on-*`, jamais par `#fff` en dur.
     *
     * **Pourquoi.** L'action primaire s'inverse en thème sombre (fond clair,
     * texte foncé) pour rester visible. Un `color: #fff` en dur sur ce fond
     * donne du blanc sur clair. Le défaut est invisible en thème clair, où il
     * fonctionne — donc il survit à tous les tests visuels faits en clair.
     */
    public function test_les_fonds_inversibles_utilisent_un_jeton_de_contraste(): void
    {
        $css = (string) file_get_contents($this->cssPath());

        preg_match_all(
            '/background:\s*var\(--color-primary\)[^;}]*color:\s*(#fff|#ffffff|white)\b/i',
            $css,
            $matches
        );

        $this->assertSame(
            [],
            $matches[0],
            'Un fond `--color-primary` porte un texte blanc en dur. Ce fond s\'inverse'
            .' en thème sombre (il devient clair) : le texte doit utiliser'
            .' `var(--color-on-primary)`, sinon le libellé devient blanc sur blanc.'
            ."\n  Occurrences : ".implode(' | ', $matches[0])
        );
    }

    /**
     * Les classes du design system utilisées par les vues doivent exister.
     *
     * **Le défaut que ce test rend impossible.** Même famille que les jetons
     * manquants, mais pour les CLASSES : `class="banner banner-info"` alors que
     * seule `.banner` est définie produit un encadré neutre au lieu de la
     * variante voulue. Aucune erreur, aucun log — l'élément s'affiche, il est
     * simplement faux. Cas réel rencontré sur `banner-info`.
     *
     * **Périmètre volontairement limité aux préfixes du design system.** Les
     * classes utilitaires Tailwind (`flex`, `mt-4`, `max-w-xl`…) proviennent du
     * CDN et ne sont pas dans ce fichier : les inclure ferait échouer le test
     * sur du code correct. On ne vérifie donc que les familles que ce fichier
     * possède réellement.
     */
    public function test_les_classes_du_design_system_utilisees_existent(): void
    {
        $css = (string) file_get_contents($this->cssPath());

        // Familles définies dans le design system (préfixes observés). Une
        // classe d'une de ces familles qui n'existe pas est une faute de frappe
        // ou une variante oubliée, pas du Tailwind.
        //
        // `toast` y est entré après un défaut réel : le CSS définissait
        // `.toast.success` (convention BEM) alors que les vues écrivaient
        // `toast-success` (convention Laravel, héritée de `session('success')`).
        // Aucune règle ne s'appliquait, donc TOUS les toasts se ressemblaient —
        // succès, avertissement et erreur compris.
        $familles = [
            'banner', 'btn', 'card', 'chat', 'data-table', 'doc', 'stat-item',
            'quota-row', 'badge', 'model-menu', 'upload-step', 'flow-step',
            'template-card', 'table-wrap', 'form-control', 'progress', 'toast',
        ];

        $motif = '/\.('.implode('|', array_map('preg_quote', $familles)).')[a-z0-9-]*/';
        preg_match_all($motif, $css, $definies);
        $definies = array_unique($definies[0]);

        $manquantes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // On ne lit que les attributs class="…" littéraux : une classe
            // construite dynamiquement (`btn-{{ $type }}`) est évaluée à
            // l'exécution et ne peut pas être vérifiée statiquement.
            preg_match_all('/class="([^"{}]*?)"/', $content, $attributs);

            foreach ($attributs[1] as $attribut) {
                foreach (preg_split('/\s+/', trim($attribut)) as $classe) {
                    if ($classe === '') {
                        continue;
                    }

                    // La classe appartient-elle à une famille du design system ?
                    $famille = null;
                    foreach ($familles as $f) {
                        if ($classe === $f || str_starts_with($classe, $f.'-')) {
                            $famille = $f;
                            break;
                        }
                    }

                    if ($famille === null) {
                        continue;
                    }

                    if (! in_array('.'.$classe, $definies, true)) {
                        $manquantes['.'.$classe][] = $file->getFilename();
                    }
                }
            }
        }

        $rapport = '';
        foreach ($manquantes as $classe => $fichiers) {
            $rapport .= sprintf(
                "\n  %s — utilisé par : %s",
                $classe,
                implode(', ', array_values(array_unique($fichiers)))
            );
        }

        $this->assertSame(
            [],
            array_keys($manquantes),
            'Des classes du design system sont utilisées dans les vues sans être'
            .' définies dans formadoc.css. L\'élément s\'affiche quand même, mais'
            .' sans la variante voulue : le défaut est silencieux.'.$rapport
        );
    }
}
