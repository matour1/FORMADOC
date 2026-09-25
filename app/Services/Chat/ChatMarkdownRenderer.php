<?php

declare(strict_types=1);

namespace App\Services\Chat;

use Illuminate\Support\Facades\Blade;

/**
 * Rendu du markdown des réponses de l'IA, en HTML sûr.
 *
 * **Le défaut que ce service corrige, et il était visible à l'écran.** Le modèle
 * répond en markdown — c'est son format naturel, et le prompt système le lui
 * demande. Les réponses étaient affichées avec `nl2br(e($content))`, donc en texte
 * brut : l'utilisateur voyait littéralement `### Résumé de l'analyse` et
 * `**Titres détectés** : 73 titres`. Vérifié sur un message réel en base, qui
 * contenait un titre, du gras, une liste à puces et une liste numérotée — tous
 * rendus tels quels.
 *
 * **Pourquoi pas une bibliothèque.** `league/commonmark` est la réponse habituelle,
 * mais l'ajouter est une décision de dépendance qui appartient au propriétaire du
 * projet (règle : « ne pas changer les dépendances sans accord »). Le balisage
 * réellement produit par le modèle est étroit — titres, gras, italique, code,
 * listes, liens — donc un rendu minimal couvre le besoin.
 *
 * **La sécurité, et pourquoi elle impose l'ordre des opérations.** Le contenu vient
 * d'un modèle qui a lu le document de l'utilisateur : il est donc NON FIABLE. Si un
 * document contient un texte malveillant, le modèle peut le recopier dans sa
 * réponse — c'est un vecteur d'injection direct vers la page.
 *
 * L'ordre est donc non négociable :
 *
 *   1. `e()` sur TOUT le contenu, AVANT toute transformation. Après cette ligne,
 *      aucun `<` ni `>` ne subsiste : aucune balise ne peut être injectée.
 *   2. On ne reconnaît ensuite qu'un jeu FERMÉ de motifs, et on ne produit que des
 *      balises de cette liste. Un motif non reconnu reste du texte échappé.
 *
 * **Pourquoi un traitement LIGNE PAR LIGNE et non des expressions régulières
 * séquentielles sur toute la chaîne.** La première version appliquait les
 * transformations les unes après les autres sur le texte entier, et trois défauts en
 * sont sortis, tous observés au test :
 *
 *   - `rapport_final.docx` devenait `rapport<em>final.docx` : les underscores d'un
 *     nom de fichier étaient pris pour de l'italique. C'est le cas NOMINAL — les
 *     fichiers générés contiennent presque toujours un underscore.
 *   - le contenu d'un bloc de code était transformé : `**pas du gras**` devenait du
 *     gras. Un bloc de code doit être littéral.
 *   - `nl2br` insérait des `<br>` entre les balises d'une liste, produisant
 *     `<ul><br /><li>` — une liste dont l'espacement était incohérent.
 *
 * Chaque étape cassait le résultat de la précédente. Un traitement par ligne, où
 * l'on décide du TYPE de chaque ligne avant de la transformer, rend ces
 * interférences impossibles : une ligne est un titre, une puce, du code ou du
 * texte — jamais deux à la fois.
 */
class ChatMarkdownRenderer
{
    /**
     * Motif d'un chemin de fichier généré, éventuellement noyé dans une phrase
     * (« Télécharger : chat/generated/x.docx »).
     *
     * Le prompt système demande au modèle de fournir le lien de téléchargement sous
     * ce format. Le modèle s'exécute, mais le chemin s'affichait en texte :
     * l'utilisateur ne pouvait pas cliquer. Ces chemins deviennent des liens vers la
     * route de téléchargement sécurisée (propriété vérifiée côté contrôleur).
     */
    private const MOTIF_CHEMIN = '#(chat/generated/[A-Za-z0-9._/-]+|claude-skills/[A-Za-z0-9._/-]+)#';

    /**
     * Rend un message en HTML sûr.
     */
    public function render(string $contenu): string
    {
        if (trim($contenu) === '') {
            return '';
        }

        // ÉTAPE 1 — échappement intégral et inconditionnel.
        // Après cette ligne, il ne reste aucun caractère `<` ni `>` dans le texte.
        $texte = e($contenu);

        $sortie = [];
        $listeOuverte = null;
        $dansCode = false;
        $code = [];
        $lignePrecedenteEstTexte = false;

        foreach (explode("\n", $texte) as $ligne) {
            // --- Délimiteur de bloc de code -----------------------------------
            if (preg_match('/^\s*```/', $ligne) === 1) {
                if ($listeOuverte !== null) {
                    $sortie[] = '</'.$listeOuverte.'>';
                    $listeOuverte = null;
                }

                if (! $dansCode) {
                    $dansCode = true;
                    $code = [];
                } else {
                    // Fin du bloc : son contenu n'a subi AUCUNE transformation,
                    // donc `**` y reste littéral.
                    $sortie[] = '<pre class="msg-code"><code>'.implode("\n", $code).'</code></pre>';
                    $dansCode = false;
                }

                $lignePrecedenteEstTexte = false;

                continue;
            }

            if ($dansCode) {
                $code[] = $ligne;

                continue;
            }

            // --- Ligne vide ---------------------------------------------------
            if (trim($ligne) === '') {
                if ($listeOuverte !== null) {
                    $sortie[] = '</'.$listeOuverte.'>';
                    $listeOuverte = null;
                }

                $lignePrecedenteEstTexte = false;

                continue;
            }

            // --- Titre --------------------------------------------------------
            $dietes = strspn(ltrim($ligne), '#');

            if ($dietes >= 1 && $dietes <= 6 && preg_match('/^#{1,6}\s+(.+)$/', trim($ligne), $m) === 1) {
                if ($listeOuverte !== null) {
                    $sortie[] = '</'.$listeOuverte.'>';
                    $listeOuverte = null;
                }

                // Niveau plafonné à 4 : un `#####` du modèle produirait un `<h5>`
                // qui écraserait la hiérarchie de la page, laquelle a déjà ses
                // propres titres.
                $niveau = min(4, max(2, $dietes));

                $sortie[] = '<h'.$niveau.' class="msg-heading">'
                    .$this->enrichirInline(trim($m[1]))
                    .'</h'.$niveau.'>';

                $lignePrecedenteEstTexte = false;

                continue;
            }

            // --- Élément de liste ---------------------------------------------
            $puce = preg_match('/^\s*[-*+]\s+(.+)$/', $ligne, $mPuce) === 1;
            $numero = preg_match('/^\s*\d+[.)]\s+(.+)$/', $ligne, $mNumero) === 1;

            if ($puce || $numero) {
                $voulu = $puce ? 'ul' : 'ol';

                if ($listeOuverte !== $voulu) {
                    if ($listeOuverte !== null) {
                        $sortie[] = '</'.$listeOuverte.'>';
                    }
                    $sortie[] = '<'.$voulu.' class="msg-list">';
                    $listeOuverte = $voulu;
                }

                // Les `<li>` sont collés (aucun saut de ligne entre eux) : un saut
                // déclencherait un <br> parasite dans la liste.
                $sortie[] = '<li>'.$this->enrichirInline(trim($puce ? $mPuce[1] : $mNumero[1])).'</li>';
                $lignePrecedenteEstTexte = false;

                continue;
            }

            // --- Ligne de texte ordinaire -------------------------------------
            if ($listeOuverte !== null) {
                $sortie[] = '</'.$listeOuverte.'>';
                $listeOuverte = null;
            }

            // Un `<br>` n'est ajouté QU'entre deux lignes de texte consécutives.
            // C'est ce qui reproduit le saut de ligne voulu sans en insérer autour
            // des blocs (titre, liste, code), où il produirait un espacement
            // incohérent.
            $sortie[] = ($lignePrecedenteEstTexte ? '<br />' : '').$this->enrichirInline($ligne);
            $lignePrecedenteEstTexte = true;
        }

        if ($listeOuverte !== null) {
            $sortie[] = '</'.$listeOuverte.'>';
        }

        // Bloc de code non fermé : on rend quand même son contenu, sinon la fin du
        // message disparaîtrait silencieusement.
        if ($dansCode && $code !== []) {
            $sortie[] = '<pre class="msg-code"><code>'.implode("\n", $code).'</code></pre>';
        }

        return implode("\n", $sortie);
    }

    /**
     * Transformations à l'intérieur d'une ligne : chemins, liens, emphase.
     *
     * **L'ordre interne compte aussi.** Les liens sont produits EN PREMIER, puis
     * l'emphase s'applique sur le reste seulement. Sans ce découpage, l'emphase
     * s'appliquerait à l'intérieur des attributs `href` — et un underscore dans un
     * chemin casserait l'URL.
     */
    private function enrichirInline(string $ligne): string
    {
        // Les balises déjà produites sont mises de côté le temps de l'emphase.
        $balises = [];

        $protege = (string) preg_replace_callback(
            '#<a\b[^>]*>.*?</a>#s',
            function (array $m) use (&$balises): string {
                $cle = "\x00".count($balises)."\x00";
                $balises[$cle] = $m[0];

                return $cle;
            },
            $this->liensDeTelechargement($this->liensMarkdown($ligne))
        );

        $protege = $this->emphase($protege);

        return strtr($protege, $balises);
    }

    /**
     * Gras, italique et code en ligne.
     *
     * L'ordre compte : le gras (`**`) avant l'italique (`*`), sinon les deux
     * étoiles qui encadrent un mot gras seraient vues comme deux italiques vides.
     */
    private function emphase(string $texte): string
    {
        // Code en ligne : AVANT l'emphase, pour que son contenu y échappe.
        $texte = (string) preg_replace('/`([^`\n]+)`/', '<code>\\1</code>', $texte);

        // Gras : `**texte**` puis `__texte__`.
        $texte = (string) preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/s', '<strong>\\1</strong>', $texte);
        $texte = (string) preg_replace('/__(?!\s)(.+?)(?<!\s)__/s', '<strong>\\1</strong>', $texte);

        // Italique par étoile : un `*` isolé, encadrant un texte sans espace en
        // bord. `(?!\*)` évite de reprendre une étoile d'un gras déjà transformé.
        $texte = (string) preg_replace(
            '/(?<!\*)\*(?!\*)(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/',
            '<em>\\1</em>',
            $texte
        );

        // Italique par underscore : exige une frontière de mot des DEUX côtés.
        //
        // C'est ce qui distingue `_texte_` (italique) de `rapport_final.docx` (nom
        // de fichier). Sans cette exigence, `_final.` serait vu comme un début
        // d'italique, et le nom du fichier — le cas NOMINAL — s'afficherait
        // tronqué au milieu.
        $texte = (string) preg_replace(
            '/(?<![\w_])_(?![\s_])([^_\n]+?)(?<![\s_])_(?![\w_])/',
            '<em>\\1</em>',
            $texte
        );

        return $texte;
    }

    /**
     * Rend les chemins de fichiers générés cliquables.
     *
     * Le motif ne peut capturer de balise : le texte est déjà échappé.
     */
    private function liensDeTelechargement(string $ligne): string
    {
        $route = route('chat.files.download');

        return (string) preg_replace_callback(
            self::MOTIF_CHEMIN,
            function (array $m) use ($route): string {
                $chemin = $m[1];
                $nom = basename($chemin);

                // `urlencode` : un nom de fichier peut contenir des espaces ou des
                // accents, et l'URL serait invalide sans encodage.
                return '<a href="'.$route.'?file='.urlencode($chemin).'"'
                    .' class="msg-file-link"'
                    .' download="'.e($nom).'">'
                    .e($nom)
                    .'</a>';
            },
            $ligne
        );
    }

    /**
     * Liens markdown `[libellé](url)`.
     *
     * **Seuls `http` et `https` sont acceptés.** Un `javascript:` est un vecteur
     * d'exécution, et le contenu vient d'un modèle qui a lu un document non fiable.
     *
     * Un schéma non autorisé n'est PAS rendu en lien, mais il est aussi retiré de
     * l'affichage du libellé : laissé tel quel, l'utilisateur lirait
     * `[clique](javascript:alert(1))` — du balisage technique dans une réponse
     * destinée à un étudiant. On ne conserve que le libellé, ce qui donne
     * « clique » : lisible, et sans lien.
     */
    private function liensMarkdown(string $ligne): string
    {
        // 1. Liens autorisés.
        $ligne = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            fn (array $m): string => '<a href="'.e($m[2]).'" target="_blank" rel="noopener noreferrer">'
                .$m[1].'</a>',
            $ligne
        );

        // 2. Liens à schéma refusé : on ne garde que le libellé.
        return (string) preg_replace(
            '/\[([^\]]+)\]\((?!https?:\/\/)[a-zA-Z][a-zA-Z0-9+.-]*:[^\s)]*\)/',
            '\\1',
            $ligne
        );
    }

    /**
     * Enregistre la directive Blade `@markdown`.
     *
     * Sans elle, chaque vue écrirait `{!! app(...)->render($c) !!}` — une forme qui
     * invite à retirer l'échappement par erreur. La directive dit ce qu'elle fait.
     */
    public static function register(): void
    {
        Blade::directive('markdown', function (string $expression): string {
            return "<?php echo app(\App\Services\Chat\ChatMarkdownRenderer::class)->render($expression); ?>";
        });
    }
}
