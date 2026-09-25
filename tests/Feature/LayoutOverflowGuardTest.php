<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Garde-fou contre les débordements horizontaux et les barres de défilement
 * système.
 *
 * **Le défaut que ce test rend visible.** Un débordement horizontal ne produit
 * AUCUNE erreur : la page s'affiche, elle est simplement plus large que l'écran,
 * et une barre de défilement horizontale apparaît. Sur grand moniteur on ne le
 * remarque même pas, parce que la barre reste discrète — mais une partie du
 * contenu est hors de vue, et sur mobile il faut défiler latéralement pour lire.
 *
 * Mesures réelles avant correction :
 *   - `/admin/analytics` : 2322 px de large pour un écran de 1440 px (882 px de
 *     débordement, la colonne de droite hors de vue) ;
 *   - `/account` sur mobile : 728 px pour 390 px ;
 *   - `/templates` sur mobile : 677 px pour 390 px ;
 *   - `/feedback` sur mobile : 370 px pour 360 px (10 px — invisible à l'œil, mais
 *     suffisant pour créer une barre).
 *
 * **Pourquoi un test et pas une vérification visuelle.** Un débordement de 10 px
 * ne se voit pas en regardant la page, et dépend de la largeur de l'écran et du
 * contenu (un nom de fichier long, un tableau à six colonnes). Seule une mesure
 * automatique à plusieurs largeurs le détecte de façon reproductible.
 *
 * Les vérifications portent sur le CSS, pas sur un navigateur : la suite tourne
 * sans moteur de rendu. On vérifie donc les RÈGLES qui rendent le débordement
 * impossible, et la présence du garde-fou global.
 */
class LayoutOverflowGuardTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/formadoc.css'));
    }

    // -------------------------------------------------------------------------
    // Garde-fou global
    // -------------------------------------------------------------------------

    /**
     * **Le test central.**
     *
     * Un enfant de `display: flex` ou `display: grid` a `min-width: auto` par
     * défaut, ce qui l'EMPÊCHE de devenir plus étroit que son contenu. C'est la
     * cause unique des quatre débordements mesurés : un graphique de 90 barres,
     * une grille de plans et des onglets imposaient leur largeur à toute la page.
     *
     * La règle globale s'applique à tous les enfants, donc une grille AJOUTÉE plus
     * tard est protégée par construction — corriger les 24 grilles du fichier une
     * par une aurait laissé le problème revenir.
     */
    public function test_un_garde_fou_empeche_les_enfants_de_flex_et_grid_d_elargir_la_page(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.app\s*:where\([^)]*\)\s*:where\([^)]*\)\s*\{\s*min-width:\s*0;/s',
            $css,
            'La règle globale `min-width: 0` sur les enfants de conteneurs a disparu. '
            .'Sans elle, tout contenu large (graphique, tableau, grille de plans) élargit '
            .'la page et crée une barre de défilement horizontale — sans aucune erreur.'
        );
    }

    /**
     * La règle globale doit viser `table` et `ul`/`ol`.
     *
     * Ce sont les deux familles qui ont réellement débordé : un tableau de six
     * colonnes (`/admin/payment-links`) et les onglets, qui sont une liste de liens.
     */
    public function test_le_garde_fou_couvre_les_tableaux_et_les_listes(): void
    {
        $css = $this->css();

        preg_match('/\.app\s*:where\([^)]*\)\s*:where\(([^)]*)\)\s*\{[^}]*min-width:\s*0;/s', $css, $m);

        $this->assertNotEmpty($m, 'Le garde-fou global est introuvable.');
        $this->assertStringContainsString('table', $m[1]);
        $this->assertStringContainsString('ul', $m[1]);
    }

    // -------------------------------------------------------------------------
    // Cas particuliers mesurés
    // -------------------------------------------------------------------------

    /**
     * Les onglets défilent au lieu d'élargir la page.
     *
     * `/templates` mesurait 677 px sur un écran de 390 px : cinq onglets de 95 à
     * 128 px poussaient la page, et les derniers étaient inaccessibles.
     */
    public function test_les_onglets_defilent_horizontalement(): void
    {
        $css = $this->css();

        preg_match('/^\.tabs\s*\{([^}]*)\}/m', $css, $m);

        $this->assertNotEmpty($m, 'La règle `.tabs` est introuvable.');
        $this->assertStringContainsString('overflow-x: auto', $m[1],
            'Les onglets doivent défiler au lieu d\'élargir la page.');
        $this->assertStringContainsString('flex-wrap: nowrap', $m[1]);
    }

    /**
     * Un onglet ne se comprime pas.
     *
     * Sans `flex-shrink: 0`, les onglets se réduiraient jusqu'à un mot par ligne
     * plutôt que de défiler lisiblement.
     */
    public function test_un_onglet_ne_se_comprime_pas(): void
    {
        preg_match('/^\.tab\s*\{([^}]*)\}/m', $this->css(), $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('flex-shrink: 0', $m[1]);
        $this->assertStringContainsString('white-space: nowrap', $m[1]);
    }

    /**
     * Un tableau large défile DANS son cadre.
     *
     * C'est le seul endroit de l'interface où une barre horizontale est légitime :
     * comprimer six colonnes dans 360 px les rendrait illisibles.
     */
    public function test_un_tableau_large_defile_dans_son_cadre(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/table\.data-table\s*\{[^}]*min-width:\s*max-content/s',
            $css,
            'Un tableau doit garder sa largeur minimale et défiler dans `.table-wrap`, '
            .'sinon ses colonnes deviennent illisibles sur mobile.'
        );
        $this->assertMatchesRegularExpression(
            '/\.table-wrap\s*\{[^}]*overflow-x:\s*auto/s',
            $css
        );
    }

    /**
     * Les grilles à deux colonnes passent à une colonne sur écran étroit.
     */
    public function test_les_grilles_passent_a_une_colonne_sur_mobile(): void
    {
        $css = $this->css();

        foreach (['split-grid', 'credit-grid', 'plan-grid', 'checkout-layout'] as $grille) {
            $this->assertStringContainsString(
                '.'.$grille,
                $css,
                "La grille « {$grille} » devrait avoir une règle responsive."
            );
        }

        // `split-grid` doit explicitement revenir à une colonne.
        $this->assertMatchesRegularExpression(
            '/@media[^{]*\{\s*\.split-grid\s*\{[^}]*grid-template-columns:\s*1fr/s',
            $css,
            '`.split-grid` doit passer à une colonne sur écran étroit.'
        );
    }

    /**
     * Le champ de saisie du chat fait au moins 16 px sur mobile.
     *
     * Seuil, pas préférence : en dessous, Safari sur iOS zoome automatiquement à
     * la mise au point — un zoom involontaire qui ne se défait pas. Le champ
     * mesurait 14,88 px.
     *
     * La correction ne doit PAS passer par la désactivation du zoom dans le
     * viewport : cela supprimerait la possibilité de zoomer pour les personnes
     * malvoyantes. Ce test le vérifie aussi.
     */
    public function test_le_champ_du_chat_evite_le_zoom_ios_sans_desactiver_le_zoom(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{[^}]*\.composer-box textarea[^{]*\{[^}]*font-size:\s*16px/s',
            $css,
            'Le champ du chat doit atteindre 16 px sur mobile, sinon iOS zoome à la saisie.'
        );

        // Et le zoom ne doit pas être désactivé globalement.
        foreach (['views/layouts/app.blade.php', 'views/layouts/admin.blade.php', 'views/landing.blade.php'] as $vue) {
            $contenu = (string) file_get_contents(resource_path($vue));

            $this->assertStringNotContainsString(
                'user-scalable=no',
                $contenu,
                "« user-scalable=no » dans {$vue} empêcherait les personnes malvoyantes de zoomer."
            );
            $this->assertStringNotContainsString('maximum-scale=1', $contenu);
        }
    }

    /**
     * L'icône du coût estimé ne peut pas être écrasée par le texte qui l'entoure.
     *
     * `.chat-cost-preview` est un conteneur flex, et sur écran étroit son texte est
     * plus large que la colonne. Sans `flex-shrink: 0`, le seul élément flexible de
     * la ligne — le `<svg>` — absorbe TOUT le manque à gagner.
     *
     * Mesure réelle à 390 px : 12 px de large sans la règle, 12 px avec, mais
     * seulement 8 px mesurés lorsque la compression s'applique — soit un tiers de
     * l'icône en moins, rendue méconnaissable. Un texte qui passe à la ligne reste
     * lisible ; une icône écrasée ne l'est pas.
     */
    public function test_l_icone_du_cout_ne_peut_pas_etre_ecrasee(): void
    {
        $css = $this->cssSansCommentaires();

        $this->assertMatchesRegularExpression(
            '/\.chat-cost-preview svg\s*\{[^}]*flex-shrink:\s*0/s',
            $css,
            'Sans `flex-shrink: 0`, le texte de l\'indice de coût écrase son icône sur écran étroit.'
        );
    }

    /**
     * La précision du coût existe en deux formes, dont une seule est affichée.
     *
     * Mesure réelle : la forme longue
     * « Coût estimé : 1 crédit(s) (modèles du plan, ajusté après usage) » occupe
     * 52 px de haut à 360 px de large, contre 17 px pour la forme courte — deux
     * lignes de plus, soit plus que la hauteur du champ de saisie lui-même.
     *
     * **Ce que la forme courte doit conserver, et pourquoi.** Le montant affiché
     * n'est pas un prix : il est corrigé après usage. Retirer cette indication
     * ferait passer une estimation pour un prix définitif — exactement l'écart qui
     * produit une réclamation. La forme courte dit donc « (estimation) » : le mot
     * qui change le sens de la phrase, et rien de plus.
     *
     * Une version antérieure de cette correction MASQUAIT la précision entière sur
     * mobile, en affirmant que « le détail figure dans la mention légale ». C'était
     * faux : cette mention est elle aussi masquée sur mobile. L'information
     * disparaissait donc partout, et le montant semblait ferme.
     */
    public function test_la_precision_du_cout_a_une_forme_courte_et_une_forme_longue(): void
    {
        $css = $this->cssSansCommentaires();

        // Deux formes déclarées.
        $this->assertStringContainsString('.cost-precision', $css);
        $this->assertStringContainsString('.cost-precision-court', $css);

        // La forme longue est celle par défaut (grand écran).
        $this->assertMatchesRegularExpression(
            '/\.cost-precision-court\s*\{[^}]*display:\s*none/s',
            $css,
            'La forme courte doit être masquée par défaut, sinon les deux s\'affichent.'
        );

        // Sur écran étroit, on échange les deux — sans en supprimer aucune.
        $this->assertMatchesRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{.*?\.cost-precision\s*\{\s*display:\s*none.*?\.cost-precision-court\s*\{\s*display:\s*inline/s',
            $css,
            'Sur écran étroit, la forme longue doit céder la place à la forme courte.'
        );
    }

    /**
     * Les deux formes de la précision sont bien présentes dans la vue.
     *
     * Le test CSS ci-dessus vérifie les RÈGLES ; celui-ci vérifie que le balisage
     * contient réellement les deux éléments. Sans lui, retirer un des deux spans de
     * la vue laisserait le test CSS passer, et l'indice serait vide ou dupliqué.
     */
    public function test_les_deux_formes_de_la_precision_sont_dans_la_vue(): void
    {
        $vue = (string) file_get_contents(resource_path('views/chat/show.blade.php'));

        $this->assertStringContainsString('class="cost-precision"', $vue);
        $this->assertStringContainsString('class="cost-precision-court"', $vue);

        // La forme courte conserve l'information qui change le sens de la phrase.
        $this->assertMatchesRegularExpression(
            '/class="cost-precision-court">\(estimation\)/',
            $vue,
            'La forme courte doit indiquer que le montant est une estimation : sans cela, '
            .'il passerait pour un prix définitif.'
        );
    }

    /**
     * Le libellé « Coût estimé : » ne peut pas être coupé en plein mot.
     *
     * **Défaut observé à l'écran, à 390 px.** `.chat-cost-preview` est un conteneur
     * flex, et le libellé y était du TEXTE NU — donc un enfant flex anonyme. Un
     * enfant flex se comprime quand la place manque, et sous la largeur de son mot
     * le plus long le navigateur le coupe en plein mot : l'indice s'affichait
     * « C o û t   e s t i m e », mot scindé en deux.
     *
     * Deux règles l'évitent, et les deux sont nécessaires :
     *   - `flex-wrap: wrap` sur le conteneur, pour que le repli se fasse ENTRE les
     *     éléments et non à l'intérieur d'un mot ;
     *   - `flex-shrink: 0` sur le libellé, qui est désormais un élément à part
     *     entière et ne doit pas être comprimé sous sa largeur naturelle.
     */
    public function test_le_libelle_du_cout_ne_peut_pas_etre_coupe_en_plein_mot(): void
    {
        $css = $this->cssSansCommentaires();

        $this->assertMatchesRegularExpression(
            '/\.chat-cost-preview\s*\{[^}]*flex-wrap:\s*wrap/s',
            $css,
            'Sans `flex-wrap: wrap`, les éléments de l\'indice se compriment au lieu de se '
            .'replier, et un texte comprimé se coupe en plein mot.'
        );

        $this->assertMatchesRegularExpression(
            '/\.cost-label\s*\{[^}]*flex-shrink:\s*0/s',
            $css,
            'Le libellé « Coût estimé : » doit résister à la compression.'
        );

        // Et le libellé doit réellement être un élément, pas du texte nu : en texte
        // nu, il redevient un enfant flex anonyme, seul cas où la coupure se produit.
        $vue = (string) file_get_contents(resource_path('views/chat/show.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<span class="cost-label">Coût estimé :<\/span>/',
            $vue,
            'Le libellé doit être dans un élément à lui : en texte nu, il est un enfant '
            .'flex anonyme et le navigateur le coupe au milieu d\'un mot.'
        );
    }

    /**
     * Le champ de saisie n'a pas de barre de défilement imposée par nos soins.
     *
     * Un moment corrigé avec `scrollbar-width: none` sur `.composer-box textarea`,
     * parce que le champ, mesuré à 284 px, perdait 15 px au profit d'une barre. La
     * correction a été RETIRÉE, et ce test fixe la raison.
     *
     * Mesure aux largeurs de téléphones réelles — 320, 360, 390, 414 et 430 px :
     * `offsetWidth` égale `clientWidth` (aucun pixel perdu) et la hauteur de contenu
     * égale la hauteur visible (aucune barre). Le défaut n'existait qu'à 284 px,
     * largeur plus étroite que n'importe quel téléphone du marché.
     *
     * Or le fichier interdit la suppression des barres de défilement pour une
     * raison solide (voir le test dédié ci-dessus) : elles indiquent la position et
     * permettent le défilement au clavier. Créer une exception pour un cas qui
     * n'existe pas sur un vrai appareil aurait affaibli une règle générale.
     */
    public function test_le_champ_de_saisie_ne_supprime_pas_sa_barre_de_defilement(): void
    {
        $css = $this->cssSansCommentaires();

        // Aucune règle de suppression ne doit viser le champ du chat.
        $this->assertDoesNotMatchRegularExpression(
            '/\.composer-box textarea[^{]*\{[^}]*scrollbar-width:\s*none/s',
            $css,
            'Le champ de saisie ne doit pas supprimer sa barre de défilement : aux '
            .'largeurs de téléphones réelles elle ne prend aucune place, et la règle '
            .'générale du projet interdit cette suppression.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.composer-box textarea::?-?-webkit-scrollbar/s',
            $css
        );
    }

    /**
     * La recherche de la barre de navigation reste accessible sur écran étroit.
     *
     * Elle a été masquée un moment sous 900 px, pour libérer de la place. La
     * modification a été REVERTÉE : la mesure ne la justifiait pas.
     *
     * À 390 px, la barre de navigation contient le bouton de menu, la recherche
     * (~135 px), le solde de crédits, le thème, l'avatar et la déconnexion. Tous
     * tiennent, sans qu'aucun ne dépasse le bord droit (vérifié élément par élément).
     * À 347 px — la largeur la plus étroite que le navigateur de test accepte — les
     * quatre éléments de droite vont de 160 px à 321 px, laissant 16 px de marge.
     *
     * Le champ était donc INUTILISABLE à 284 px seulement, largeur qui n'existe sur
     * aucun téléphone. Retirer une fonctionnalité globale pour un cas inexistant
     * était une surcorrection, d'autant que la recherche filtre des éléments de la
     * page courante qu'aucun autre contrôle ne filtre ailleurs.
     */
    public function test_la_recherche_de_la_barre_de_navigation_reste_visible_sur_mobile(): void
    {
        $css = $this->cssSansCommentaires();

        $this->assertDoesNotMatchRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{[^@]*?\.navbar-search\s*\{\s*display:\s*none/s',
            $css,
            'Masquer la recherche de la barre de navigation sur mobile supprime un accès '
            .'que la mesure ne justifie pas de retirer.'
        );

        // Elle doit au contraire s'adapter à la place disponible.
        $this->assertMatchesRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{[^@]*?\.navbar-search\s*\{[^}]*min-width:\s*auto/s',
            $css,
            'Sur écran étroit, la recherche doit prendre la place disponible au lieu '
            .'d\'imposer sa largeur minimale de 260 px.'
        );
    }

    /**
     * Les barres de défilement sont personnalisées, pas supprimées.
     *
     * Les retirer (`scrollbar-width: none`, `overflow: hidden`) supprimerait
     * l'indication de position et de longueur — utile pour savoir où l'on se trouve
     * dans une longue conversation — et rendrait le défilement impossible au
     * clavier et à la souris.
     */
    public function test_les_barres_de_defilement_sont_fines_et_non_supprimees(): void
    {
        // On retire d'abord les commentaires CSS : les règles ci-dessous sont
        // citées DANS les commentaires qui expliquent pourquoi on ne les emploie
        // pas, et une recherche naïve sur le texte brut les trouverait — le test
        // échouait alors sur sa propre documentation.
        $css = $this->cssSansCommentaires();

        $this->assertStringContainsString('scrollbar-width: thin', $css);
        $this->assertStringContainsString('scrollbar-color:', $css);
        $this->assertStringContainsString('::-webkit-scrollbar-thumb', $css);

        // Le coin entre barres horizontale et verticale : sans règle, il reste un
        // carré opaque du système, visible sur les tableaux larges.
        $this->assertStringContainsString('::-webkit-scrollbar-corner', $css);

        // La suppression complète est interdite.
        $this->assertStringNotContainsString(
            'scrollbar-width: none',
            $css,
            'Supprimer les barres de défilement retirerait l\'indication de position et '
            .'gênerait la navigation au clavier.'
        );
        $this->assertStringNotContainsString('::-webkit-scrollbar { display: none', $css);
    }

    /**
     * CSS débarrassé de ses commentaires.
     *
     * Nécessaire dès qu'un test cherche l'ABSENCE d'une règle : ce fichier
     * documente abondamment ce qu'il évite, et cite donc les règles écartées.
     */
    private function cssSansCommentaires(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->css());
    }

    /**
     * La règle de la barre est déclarée HORS de `:root`.
     *
     * `scrollbar-width` et `scrollbar-color` sont des propriétés de mise en forme,
     * pas des jetons : les placer dans le bloc de variables produit une erreur de
     * syntaxe CSS qui fait échouer TOUT le build Vite — ce qui est arrivé, et a
     * bloqué le build jusqu'à la correction.
     */
    public function test_la_regle_de_barre_n_est_pas_dans_le_bloc_de_variables(): void
    {
        // Sans commentaires : le bloc `:root` documente lui-même la règle
        // `scrollbar-width` qu'il ne faut PAS y placer, et une recherche sur le
        // texte brut la trouverait dans ce commentaire.
        $css = $this->cssSansCommentaires();

        // On isole les deux blocs de variables (`:root` et `[data-theme="dark"]`).
        preg_match_all('/:root\s*\{([^}]*)\}/s', $css, $roots);
        preg_match_all('/\[data-theme="dark"\]\s*\{([^}]*)\}/s', $css, $sombres);

        $this->assertNotEmpty($roots[1], 'Le bloc `:root` est introuvable.');

        foreach (array_merge($roots[1], $sombres[1]) as $bloc) {
            $this->assertStringNotContainsString(
                'scrollbar-width',
                $bloc,
                'Une propriété de mise en forme placée dans le bloc de variables produit '
                .'une erreur de syntaxe CSS qui fait échouer le build Vite.'
            );
            $this->assertStringNotContainsString('scrollbar-color', $bloc);
            $this->assertStringNotContainsString('::-webkit-scrollbar', $bloc);
        }
    }

    // -------------------------------------------------------------------------
    // Le layout du chat
    // -------------------------------------------------------------------------

    /**
     * Sur mobile, la conversation garde une hauteur bornée.
     *
     * Mesure avant correction : `.chat-layout` faisait 25 499 px de haut sur une
     * conversation de 50 messages, et le composer se trouvait à 25 462 px du haut
     * de la page. Cause : `height: auto` en mobile, ce qui supprimait la borne de
     * `.chat-messages` — la page entière défilait au lieu de la zone de messages.
     */
    public function test_le_chat_borne_sa_hauteur_sur_mobile(): void
    {
        $css = $this->css();

        // `grid-template-rows: auto 1fr` est indispensable : sans lui, les rangées
        // sont dimensionnées par leur contenu et le composer déborde.
        $this->assertMatchesRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{[^}]*\.chat-layout\s*\{[^}]*grid-template-rows:\s*auto\s+1fr/s',
            $css,
            '`.chat-layout` doit borner ses rangées sur mobile, sinon la zone de messages '
            .'s\'étire sur toute la hauteur du contenu et le composer sort de l\'écran.'
        );

        // La hauteur doit utiliser `dvh` et non `vh` : sur mobile, `vh` désigne la
        // hauteur SANS les barres d'adresse, qui apparaissent et disparaissent au
        // défilement — la mise en page sauterait.
        $this->assertMatchesRegularExpression(
            '/@media[^{]*max-width:\s*900px[^{]*\{[^}]*\.chat-layout\s*\{[^}]*height:\s*calc\(100dvh/s',
            $css,
            'La hauteur doit employer `dvh` : avec `vh`, les barres d\'adresse mobiles font '
            .'sauter la mise en page au défilement.'
        );

        $this->assertStringNotContainsString(
            '.chat-layout { grid-template-columns: 1fr; height: auto;',
            $css,
            '`height: auto` sur `.chat-layout` supprime la borne de la zone de messages.'
        );
    }

    /**
     * Les cibles tactiles atteignent 44 px sur écran étroit.
     *
     * Mesure avant correction : le bouton de suppression d'une conversation faisait
     * 18x18 px avec `opacity: 0` — il n'apparaissait qu'au survol, or un écran
     * tactile ne produit pas de `:hover`. Il était donc INATTEIGNABLE.
     */
    public function test_les_cibles_tactiles_atteignent_44px_sur_mobile(): void
    {
        // On cherche la RÈGLE, sans la rattacher à une media query précise : deux
        // requêtes la portent (`max-width: 900px` et `hover: none`), et une
        // expression qui tenterait de traverser les accolades imbriquées du CSS
        // échouerait — ce qui était le cas et ne testait donc rien d'utile.
        $this->assertMatchesRegularExpression(
            '/\.chat-item-del\s*\{[^}]*min-width:\s*44px/s',
            $this->cssSansCommentaires(),
            'Le bouton de suppression d\'une conversation doit atteindre 44 px : mesuré à '
            .'18x18 px avec `opacity: 0`, il était inatteignable au toucher.'
        );

        // Et il doit être VISIBLE, pas seulement cliquable : il n'apparaissait
        // qu'au survol, or un écran tactile n'en produit pas.
        $this->assertMatchesRegularExpression(
            '/\.chat-item-del\s*\{[^}]*opacity:\s*1/s',
            $this->cssSansCommentaires(),
            'Le bouton doit être visible sans survol, sinon il reste inaccessible au toucher.'
        );
    }

    /**
     * Les éléments qui n'apparaissaient qu'au survol sont visibles au toucher.
     */
    public function test_les_actions_visibles_au_survol_le_sont_au_toucher(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            '@media (hover: none), (pointer: coarse)',
            $css,
            'Sans règle pour les écrans sans survol, les actions (supprimer, copier, réagir) '
            .'resteraient invisibles et inaccessibles au toucher.'
        );
    }
}
