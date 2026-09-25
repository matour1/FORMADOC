<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Chat\ChatMarkdownRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rendu du markdown des réponses de l'assistant.
 *
 * **Ce que ces tests protègent, et pourquoi la sécurité passe avant le rendu.**
 * Le contenu vient d'un modèle qui a lu le document de l'utilisateur. Il est donc
 * NON FIABLE : un document peut contenir un texte conçus pour être recopié dans la
 * réponse, et ce texte atterrit dans une page HTML. Un rendu qui transforme avant
 * d'échapper ouvre une faille XSS ; un rendu qui échappe sans transformer affiche
 * du balisage brut — ce qui était le cas avant, l'utilisateur voyant littéralement
 * `### Résumé` et `**Titres détectés**`.
 *
 * Les deux propriétés sont donc testées ensemble : le HTML produit doit être lisible
 * ET inoffensif.
 */
class ChatMarkdownRendererTest extends TestCase
{
    use RefreshDatabase;

    private function rendu(string $markdown): string
    {
        return app(ChatMarkdownRenderer::class)->render($markdown);
    }

    // -------------------------------------------------------------------------
    // Sécurité — priorité absolue
    // -------------------------------------------------------------------------

    /**
     * **Le test central de sécurité.**
     *
     * Le contenu est échappé AVANT toute transformation : aucune balise fournie ne
     * peut donc survivre. Sans cet ordre, un document contenant `<script>` pourrait
     * faire exécuter du code dans la session de l'utilisateur.
     *
     * Le fournisseur est déclaré par ATTRIBUT : ce projet utilise PHPUnit 12, où
     * l'annotation `@dataProvider` n'est plus lue — le test échouait sur « Too few
     * arguments », ce qui ne dit rien de la cause.
     */
    #[DataProvider('chargesInjectives')]
    public function test_aucune_balise_fournie_ne_survit(string $charge): void
    {
        $html = $this->rendu($charge);

        $this->assertDoesNotMatchRegularExpression(
            '/<(script|iframe|svg|img|object|embed|form|link|meta)\b/i',
            $html,
            'Une balise fournie par le contenu a survécu au rendu : le contenu vient d\'un '
            .'modèle qui a lu un document non fiable, donc c\'est une faille XSS.'
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function chargesInjectives(): array
    {
        return [
            'balise script' => ['<script>alert(1)</script>'],
            'image avec gestionnaire' => ['<img src=x onerror=alert(1)>'],
            'iframe distante' => ['<iframe src="http://evil.test"></iframe>'],
            'svg avec evenement' => ['<svg/onload=alert(1)>'],
            'balise object' => ['<object data="evil.swf"></object>'],
            'formulaire' => ['<form action="http://evil.test"><input name="x"></form>'],
        ];
    }

    /**
     * Un lien `javascript:` ne doit jamais devenir cliquable.
     *
     * Seuls `http` et `https` sont acceptés. Le libellé est conservé — laisser
     * `[clique](javascript:alert(1))` à l'écran serait du balisage technique dans
     * une réponse destinée à un étudiant.
     */
    public function test_un_lien_javascript_ne_devient_pas_cliquable(): void
    {
        $html = $this->rendu('[clique](javascript:alert(1))');

        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('clique', $html, 'Le libellé doit rester lisible.');
    }

    public function test_un_lien_data_uri_ne_devient_pas_cliquable(): void
    {
        $html = $this->rendu('[x](data:text/html;base64,PHNjcmlwdD4=)');

        $this->assertStringNotContainsString('href', $html);
    }

    /**
     * Un guillemet dans un attribut ne doit pas pouvoir en sortir.
     */
    public function test_un_guillemet_ne_peut_pas_sortir_d_un_attribut(): void
    {
        $html = $this->rendu('chemin " onmouseover="alert(1)');

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    // -------------------------------------------------------------------------
    // Rendu — le défaut visible
    // -------------------------------------------------------------------------

    /**
     * **Le défaut observé sur un message réel.** Le message #71 contenait
     * `### Résumé de l'analyse :` et `**Titres détectés**`, rendus tels quels par
     * `nl2br(e(...))`.
     */
    public function test_le_markdown_n_apparait_plus_en_brut(): void
    {
        $entree = "### Résumé de l'analyse :\n\n- **Titres détectés** : 73 titres\n- **Anomalies** : aucune";

        $html = $this->rendu($entree);

        $this->assertStringNotContainsString('###', $html, 'Le balisage de titre ne doit plus être visible.');
        $this->assertStringNotContainsString('**', $html, 'Le balisage de gras ne doit plus être visible.');

        $this->assertStringContainsString('<h3 class="msg-heading">', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('<ul class="msg-list">', $html);
        $this->assertStringContainsString('<li>', $html);
    }

    public function test_les_titres_ont_un_niveau_borne(): void
    {
        // Un `#####` du modèle produirait un `<h5>` qui écraserait la hiérarchie de
        // la page, laquelle a déjà ses propres titres.
        $this->assertStringContainsString('<h4 class="msg-heading">', $this->rendu('##### Profond'));
        $this->assertStringContainsString('<h2 class="msg-heading">', $this->rendu('# Haut'));
    }

    public function test_les_listes_a_puces_et_numerotees_sont_distinguees(): void
    {
        $this->assertStringContainsString('<ul class="msg-list">', $this->rendu("- Un\n- Deux"));
        $this->assertStringContainsString('<ol class="msg-list">', $this->rendu("1. Un\n2. Deux"));
    }

    public function test_un_changement_de_type_ferme_la_premiere_liste(): void
    {
        // Sans fermeture, le `<ol>` se retrouverait imbriqué dans le `<ul>`.
        $html = $this->rendu("- Une puce\n1. Un numero");

        $this->assertStringContainsString('</ul>', $html);
        $this->assertLessThan(
            strpos($html, '<ol'),
            strpos($html, '</ul>'),
            'Le `<ul>` doit être fermé avant l\'ouverture du `<ol>`.'
        );
    }

    /**
     * **Le bug le plus coûteux du rendu initial.**
     *
     * `rapport_final.docx` devenait `rapport<em>final.docx` : les underscores d'un
     * nom de fichier étaient pris pour de l'italique. C'est le cas NOMINAL — les
     * fichiers générés contiennent presque toujours un underscore.
     */
    public function test_un_nom_de_fichier_avec_underscores_reste_intact(): void
    {
        $html = $this->rendu('Fichier : chat/generated/2026/09/19/rapport_final_v2.docx');

        $this->assertStringNotContainsString('<em>', $html,
            'Les underscores d\'un nom de fichier ne doivent pas être lus comme de l\'italique.');
        $this->assertStringContainsString('rapport_final_v2.docx', $html);
    }

    public function test_l_italique_par_underscore_fonctionne_toujours(): void
    {
        // Contrôle négatif : exiger une frontière de mot ne doit pas supprimer la
        // fonctionnalité, seulement la restreindre.
        $this->assertStringContainsString('<em>texte</em>', $this->rendu('Un _texte_ ici'));
    }

    /**
     * Le contenu d'un bloc de code est LITTÉRAL.
     *
     * Un `**` dans du code est une multiplication ou un opérateur, pas du gras ; un
     * `_` y est un underscore. Le transformer changerait le sens du code affiché.
     */
    public function test_le_contenu_d_un_bloc_de_code_n_est_pas_transforme(): void
    {
        $html = $this->rendu("```\n**pas du gras** et _pas de l'italique_\n```");

        $this->assertStringContainsString('<pre class="msg-code">', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('**pas du gras**', $html);
    }

    /**
     * Pas de `<br>` parasite autour des blocs.
     *
     * La version initiale utilisait `nl2br` sur toute la chaîne, ce qui insérait des
     * `<br>` entre les balises d'une liste (`<ul><br /><li>`) et produisait un
     * espacement incohérent.
     */
    public function test_aucun_br_parasite_dans_une_liste(): void
    {
        $html = $this->rendu("- Un\n- Deux");

        $this->assertStringNotContainsString('<ul class="msg-list"><br', $html);
        $this->assertStringNotContainsString('</li><br', $html);
    }

    public function test_les_sauts_de_ligne_du_texte_sont_conserves(): void
    {
        $html = $this->rendu("Premiere ligne\nDeuxieme ligne");

        $this->assertStringContainsString('<br />', $html);
    }

    public function test_un_contenu_vide_ne_produit_rien(): void
    {
        $this->assertSame('', $this->rendu('   '));
    }

    // -------------------------------------------------------------------------
    // Fichiers générés
    // -------------------------------------------------------------------------

    /**
     * Un chemin de fichier généré devient un lien cliquable.
     *
     * Le prompt système demande au modèle de fournir le chemin au format
     * `chat/generated/...`. Il s'exécute, mais le chemin s'affichait en texte :
     * l'utilisateur ne pouvait pas récupérer son document.
     */
    public function test_un_chemin_de_fichier_devient_un_lien(): void
    {
        $html = $this->rendu('Téléchargez : chat/generated/2026/09/25/rapport.docx');

        $this->assertStringContainsString('class="msg-file-link"', $html);
        $this->assertStringContainsString(route('chat.files.download'), $html);
        $this->assertStringContainsString('rapport.docx', $html);
    }

    /**
     * Un chemin injecté ne doit pas produire d'attribut dangereux.
     */
    public function test_un_chemin_de_fichier_ne_peut_pas_injecter_d_attribut(): void
    {
        $html = $this->rendu('chat/generated/"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertDoesNotMatchRegularExpression('/on\w+=/i', $html);
    }

    /**
     * **Le cas réellement observé en production.**
     *
     * Le prompt système demande au modèle la liste des fichiers générés « avec leur
     * lien de téléchargement (au format chat/generated/...) ». Le modèle obéit, mais
     * produit un lien markdown dont la CIBLE est un chemin relatif — pas une URL.
     *
     * Le rendu n'acceptait que `http(s)://`, donc ce lien n'était pas converti, et
     * l'utilisateur lisait le balisage en clair :
     *
     *     [Télécharger document modifié](document_modifie.docx)
     *
     * juste avant une pastille de téléchargement, elle, parfaitement fonctionnelle.
     * Deux défauts d'un coup : du balisage technique dans une réponse destinée à un
     * étudiant, et un lien inerte affiché à côté de celui qui marche.
     */
    public function test_un_lien_markdown_vers_un_fichier_genere_devient_un_lien_reel(): void
    {
        $html = $this->rendu(
            '[Télécharger document modifié](chat/generated/2026/09/19/document_modifie.docx)'
        );

        $this->assertStringContainsString('class="msg-file-link"', $html,
            'Un lien markdown vers un fichier généré doit devenir un lien de téléchargement.');
        $this->assertStringContainsString('document_modifie.docx', $html);

        // Le balisage ne doit PLUS être visible.
        $this->assertStringNotContainsString('[Télécharger', $html);
        $this->assertStringNotContainsString('](', $html);
    }

    /**
     * Le chemin relatif est accepté avec ou sans `./` et `/` initial, parce que le
     * modèle produit les trois formes selon le contexte de sa phrase.
     */
    public function test_un_chemin_relatif_est_accepte_dans_ses_trois_formes(): void
    {
        foreach (['chat/generated/a/b.docx', './chat/generated/a/b.docx', '/chat/generated/a/b.docx'] as $cible) {
            $html = $this->rendu('['.'Fichier'.']('.$cible.')');

            $this->assertStringContainsString('class="msg-file-link"', $html, 'Cible refusée : '.$cible);
        }
    }

    /**
     * Le lien reste limité aux répertoires que la route de téléchargement accepte.
     *
     * Sans cette borne, `[document](mon_rapport.docx)` — le modèle cite souvent un
     * fichier par son seul nom — produirait un lien vers un fichier inexistant.
     * Un libellé sans lien est préférable à un lien qui renvoie une erreur.
     */
    public function test_un_lien_markdown_vers_un_fichier_hors_perimetre_reste_du_texte(): void
    {
        $html = $this->rendu('[Mon rapport](mon_rapport.docx)');

        $this->assertStringNotContainsString('msg-file-link', $html,
            'Seuls les fichiers générés par le chat sont téléchargeables.');
        $this->assertStringContainsString('Mon rapport', $html);
    }

    /**
     * La cible d'un lien vers un fichier ne doit pas être altérée par l'encodage.
     *
     * `urlencode` transforme `/` en `%2F` dans le paramètre `file`, et la route
     * décode de son côté. Si le motif de reconnaissance des chemins s'appliquait
     * APRÈS le remplacement du lien, le chemin encodé serait à nouveau transformé à
     * l'intérieur de l'attribut `href` — produisant un lien imbriqué, donc cassé.
     */
    public function test_un_lien_de_fichier_n_est_pas_transformé_une_seconde_fois(): void
    {
        $html = $this->rendu('[Fichier](chat/generated/2026/09/19/rapport_final_v2.docx)');

        $this->assertSame(1, substr_count($html, '<a '),
            'Le lien doit être produit une seule fois, sans imbrication.');
        $this->assertSame(1, substr_count($html, '</a>'));
        $this->assertStringNotContainsString('<a href="<a', $html);
    }

    /**
     * Un chemin cité seulement dans une phrase continue de fonctionner.
     *
     * Contrôle de non-régression : la reconnaissance du lien markdown ne doit pas
     * avoir remplacé la reconnaissance du chemin nu, qui est la forme que le prompt
     * demande en priorité.
     */
    public function test_un_chemin_nu_reste_reconnu_apres_l_ajout_des_liens_markdown(): void
    {
        $html = $this->rendu('Le fichier est prêt : chat/generated/2026/09/19/rapport.docx');

        $this->assertStringContainsString('class="msg-file-link"', $html);
        $this->assertStringNotContainsString('chat/generated/2026/09/19/rapport.docx</a>', $html);
    }

    // -------------------------------------------------------------------------
    // Intégration sur un message réel
    // -------------------------------------------------------------------------

    /**
     * Le rendu doit fonctionner sur un message de la base, rendu par la vue.
     *
     * C'est le test qui relie le service à la page : sans lui, le service pourrait
     * être parfait et la vue continuer d'afficher du texte brut.
     */
    public function test_la_page_de_conversation_rend_le_markdown_des_messages(): void
    {
        $user = User::factory()->create(['credits_balance' => 1000]);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test markdown',
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => "### Résumé\n\n- **Titres** : 73\n- Points ambigus : aucun",
        ]);

        $reponse = $this->actingAs($user)
            ->get(route('chat.show', $session))
            ->assertOk();

        // Le HTML contient les balises produites, pas le balisage markdown.
        $reponse->assertSee('msg-heading', false);
        $reponse->assertSee('class="msg-list"', false);
        $reponse->assertDontSee('### Résumé');

        $html = $reponse->getContent();
        $this->assertStringNotContainsString('**Titres**', $html);
    }

    /**
     * La vue ne doit plus utiliser `nl2br(e(...))` pour les messages.
     *
     * Garde-fou de régression : ce test échouerait si quelqu'un revenait au rendu
     * en texte brut, qui était la cause du défaut initial.
     */
    public function test_la_vue_n_utilise_plus_nl2br_sur_le_contenu(): void
    {
        $vue = (string) file_get_contents(resource_path('views/chat/show.blade.php'));

        $this->assertStringNotContainsString(
            'nl2br(e($message->content))',
            $vue,
            'La vue doit utiliser @markdown : nl2br(e(...)) affiche le markdown en texte brut.'
        );
        $this->assertStringContainsString('@markdown($message->content)', $vue);
    }
}
