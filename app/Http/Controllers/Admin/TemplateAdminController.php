<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\Template;
use App\Services\DocumentGeneration\TemplateStyleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Gestion des gabarits de mise en forme, depuis l'espace d'administration.
 *
 * **Pourquoi cet écran.** Les gabarits étaient en base (table `templates`) mais
 * n'avaient AUCUN écran d'écriture : ni création, ni modification. La seule façon
 * d'en ajouter ou d'en corriger un était de passer par la base ou par un seeder,
 * ce qui supposait un accès technique et laissait la moindre virgule de couleur
 * exposée à une faute de frappe non détectée.
 *
 * L'écran public `/templates` existait, mais il CONSULTE : il affiche les
 * gabarits disponibles pour que l'utilisateur en choisisse un. Modifier un
 * gabarit change la mise en forme de tous les documents à venir, pour tous les
 * utilisateurs — ce n'est pas une action d'utilisateur, c'est une action
 * d'exploitation.
 *
 * **Ce que ce contrôleur ne fait pas.** Il ne touche jamais aux documents déjà
 * générés : un document reste tel qu'il a été produit, avec le gabarit appliqué
 * au moment de sa génération. Seuls les traitements FUTURS changent. Retoucher un
 * document existant serait une réécriture silencieuse de son contenu.
 */
class TemplateAdminController extends Controller
{
    /**
     * Liste des gabarits, avec leur usage réel.
     *
     * **Le nombre de documents générés est affiché** parce qu'il conditionne une
     * décision : un gabarit utilisé par des documents ne se supprime pas comme un
     * gabarit jamais employé. Sans ce chiffre, l'administrateur devrait aller le
     * chercher ailleurs — ou le découvrir après coup.
     */
    public function index(): View
    {
        $gabarits = Template::query()
            ->withCount('generatedDocuments')
            ->orderByDesc('is_public')
            ->orderBy('name')
            ->get();

        return view('admin.templates.index', [
            'templates' => $gabarits,
        ]);
    }

    /**
     * Formulaire de création.
     *
     * Le formulaire part des valeurs PAR DÉFAUT du résolveur
     * (`TemplateStyleResolver::defaults()`), et non de champs vides : créer un
     * gabarit en repartant de zéro obligerait à connaître par cœur les unités
     * (twips, points) et les clés attendues. On pré-remplit donc une base
     * saine, que l'administrateur ajuste.
     */
    public function create(): View
    {
        return view('admin.templates.form', [
            'template' => null,
            'params' => TemplateStyleResolver::defaults(),
        ]);
    }

    /**
     * Enregistre un nouveau gabarit.
     */
    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        try {
            $template = Template::create([
                'name' => $donnees['name'],
                'description' => $donnees['description'] ?? '',
                'params' => $donnees['params'],
                'is_public' => $donnees['is_public'],
            ]);

            Log::info('Gabarit créé', [
                'template_id' => $template->id,
                'name' => $template->name,
                'admin_id' => $request->user()->id,
            ]);

            return redirect()
                ->route('admin.templates.index')
                ->with('success', 'Gabarit « '.$template->name.' » créé.');
        } catch (Throwable $e) {
            Log::error('Création de gabarit impossible', [
                'name' => $donnees['name'],
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['template' => 'Création impossible : '.$e->getMessage()]);
        }
    }

    /**
     * Formulaire de modification.
     */
    public function edit(Template $template): View
    {
        return view('admin.templates.form', [
            'template' => $template,
            // Les paramètres sont NORMALISÉS avant affichage : un gabarit ancien
            // ou incomplet n'a peut-être pas toutes les clés. Sans normalisation,
            // le formulaire afficherait des champs vides là où le moteur applique
            // en réalité une valeur par défaut — l'administrateur croirait alors
            // modifier un réglage qui n'existe pas.
            'params' => TemplateStyleResolver::normalize($template->params),
        ]);
    }

    /**
     * Enregistre les modifications d'un gabarit.
     *
     * La modification n'est PAS propagée aux documents déjà générés, et cela
     * mérite d'être dit à l'administrateur : il pourrait croire que corriger un
     * gabarit corrige les documents produits avec. Ce n'est pas le cas, et c'est
     * volontaire — réécrire un document déjà livré changerait un résultat que
     * l'utilisateur a pu rendre.
     */
    public function update(Request $request, Template $template): RedirectResponse
    {
        $donnees = $this->valider($request, $template);

        try {
            $template->update([
                'name' => $donnees['name'],
                'description' => $donnees['description'] ?? '',
                'params' => $donnees['params'],
                'is_public' => $donnees['is_public'],
            ]);

            Log::info('Gabarit modifié', [
                'template_id' => $template->id,
                'name' => $template->name,
                'admin_id' => $request->user()->id,
            ]);

            $documents = GeneratedDocument::where('template_id', $template->id)->count();

            $message = 'Gabarit « '.$template->name.' » mis à jour.';

            if ($documents > 0) {
                // On annonce explicitement ce que la modification n'a PAS fait.
                // Sans cette phrase, l'administrateur supposerait raisonnablement
                // le contraire.
                $message .= ' Les '.$documents.' document(s) déjà générés avec ce gabarit '
                    .'ne sont pas modifiés : ils conservent la mise en forme appliquée '
                    .'au moment de leur génération.';
            }

            return redirect()
                ->route('admin.templates.index')
                ->with('success', $message);
        } catch (Throwable $e) {
            Log::error('Modification de gabarit impossible', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['template' => 'Modification impossible : '.$e->getMessage()]);
        }
    }

    /**
     * Supprime un gabarit, si aucun document généré n'y est rattaché.
     *
     * **Le refus est le comportement principal, pas une exception.** La colonne
     * `generated_documents.template_id` référence le gabarit : le supprimer
     * laisserait une référence orpheline, et le document perdrait l'indication de
     * la mise en forme sous laquelle il a été produit. On refuse donc, en
     * indiquant le nombre de documents concernés — l'administrateur peut alors
     * décider de dépublier le gabarit plutôt que de le supprimer.
     */
    public function destroy(Request $request, Template $template): RedirectResponse
    {
        $documents = GeneratedDocument::where('template_id', $template->id)->count();

        if ($documents > 0) {
            return redirect()
                ->route('admin.templates.index')
                ->withErrors([
                    'template' => 'Suppression refusée : '.$documents.' document(s) ont été générés '
                        .'avec « '.$template->name.' ». Supprimer le gabarit laisserait ces documents '
                        .'sans référence de mise en forme. Dépubliez-le plutôt : il disparaîtra des '
                        .'choix proposés aux utilisateurs sans casser ces documents.',
                ]);
        }

        $nom = $template->name;
        $template->delete();

        Log::info('Gabarit supprimé', [
            'name' => $nom,
            'admin_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.templates.index')
            ->with('success', 'Gabarit « '.$nom.' » supprimé.');
    }

    /**
     * Bascule la publication d'un gabarit.
     *
     * Action distincte de la modification : dépublier est la réponse COURANTE à
     * « ce gabarit ne doit plus être proposé », et elle ne touche à aucun réglage.
     * La mêler au formulaire de modification obligerait à rouvrir et réenregistrer
     * l'ensemble des paramètres pour un simple retrait de la liste.
     */
    public function togglePublic(Request $request, Template $template): RedirectResponse
    {
        $template->update(['is_public' => ! $template->is_public]);

        Log::info('Gabarit : publication basculée', [
            'template_id' => $template->id,
            'is_public' => $template->is_public,
            'admin_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.templates.index')
            ->with('success', 'Gabarit « '.$template->name.' » '
                .($template->is_public ? 'publié' : 'dépublié').'.');
    }

    /**
     * Valide et normalise la requête.
     *
     * **Les bornes ne sont pas décoratives.** Chaque paramètre finit dans un
     * document Word : une taille de police à 0 ou à 500, une couleur hors format
     * hexadécimal, une marge négative produisent soit un fichier que Word refuse
     * d'ouvrir, soit une mise en forme inutilisable. Or un gabarit est appliqué à
     * TOUS les documents suivants : une valeur absurde enregistrée ici casserait
     * la mise en forme de tous les utilisateurs, pas d'un seul.
     *
     * Les unités sont celles du moteur (PhpWord) :
     *   - tailles : POINTS, transmis tels quels à PhpWord (`size => 12` fait du
     *     12 pt) — bornées entre 6 et 72 ;
     *   - marges et espacements : twips (1440 = 1 pouce) → bornées ;
     *   - couleurs : hexadécimal à 6 chiffres, sans `#` (format PhpWord).
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?Template $template = null): array
    {
        $regles = [
            // `unique` en ignorant le gabarit courant lors d'une modification :
            // sans cela, réenregistrer un gabarit sans changer son nom échouerait
            // sur sa propre contrainte d'unicité.
            'name' => [
                'required', 'string', 'max:191',
                'unique:templates,name'.($template !== null ? ','.$template->id : ''),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['boolean'],

            // Identité visuelle
            'police' => ['required', 'string', 'max:100'],
            'interligne' => ['required', 'numeric', 'min:0.5', 'max:3'],
            'alignement_titres' => ['required', 'in:left,center,right,justify'],

            // Tailles (points, transmis tels quels à PhpWord)
            'tailles' => ['required', 'array'],
            'tailles.titre1' => ['required', 'integer', 'min:6', 'max:72'],
            'tailles.titre2' => ['required', 'integer', 'min:6', 'max:72'],
            'tailles.titre3' => ['required', 'integer', 'min:6', 'max:72'],
            'tailles.corps' => ['required', 'integer', 'min:6', 'max:72'],

            // Couleurs (hexadécimal sans #, format PhpWord)
            'couleurs' => ['required', 'array'],
            'couleurs.titre1' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'couleurs.titre2' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'couleurs.titre3' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'couleurs.corps' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],

            // Espacements (twips)
            'espacements' => ['required', 'array'],
            'espacements.avant_titre' => ['required', 'integer', 'min:0', 'max:1440'],
            'espacements.apres_titre' => ['required', 'integer', 'min:0', 'max:1440'],
            'espacements.apres_paragraphe' => ['required', 'integer', 'min:0', 'max:1440'],

            // Marges (twips)
            'marges' => ['required', 'array'],
            'marges.top' => ['required', 'integer', 'min:0', 'max:2880'],
            'marges.right' => ['required', 'integer', 'min:0', 'max:2880'],
            'marges.bottom' => ['required', 'integer', 'min:0', 'max:2880'],
            'marges.left' => ['required', 'integer', 'min:0', 'max:2880'],
            'marges.header' => ['required', 'integer', 'min:0', 'max:2880'],
            'marges.footer' => ['required', 'integer', 'min:0', 'max:2880'],

            // Tableaux
            'tableau' => ['required', 'array'],
            'tableau.style' => ['required', 'string', 'max:60'],
            'tableau.header_couleur' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'tableau.header_texte' => ['required', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'tableau.bordure' => ['boolean'],
        ];

        $donnees = $request->validate($regles);

        // Assemblage des params au format attendu par TemplateStyleResolver.
        // On ne recopie que les clés du référentiel : un champ inconnu ajouté au
        // formulaire ne doit pas se retrouver en base, où il serait silencieusement
        // ignoré par le moteur et donnerait un réglage sans effet.
        $params = [
            'police' => $donnees['police'],
            'tailles' => [
                'titre1' => (int) $donnees['tailles']['titre1'],
                'titre2' => (int) $donnees['tailles']['titre2'],
                'titre3' => (int) $donnees['tailles']['titre3'],
                'corps' => (int) $donnees['tailles']['corps'],
            ],
            'couleurs' => [
                'titre1' => mb_strtoupper($donnees['couleurs']['titre1']),
                'titre2' => mb_strtoupper($donnees['couleurs']['titre2']),
                'titre3' => mb_strtoupper($donnees['couleurs']['titre3']),
                'corps' => mb_strtoupper($donnees['couleurs']['corps']),
            ],
            'interligne' => (float) $donnees['interligne'],
            'espacements' => [
                'avant_titre' => (int) $donnees['espacements']['avant_titre'],
                'apres_titre' => (int) $donnees['espacements']['apres_titre'],
                'apres_paragraphe' => (int) $donnees['espacements']['apres_paragraphe'],
            ],
            'alignement_titres' => $donnees['alignement_titres'],
            'marges' => [
                'top' => (int) $donnees['marges']['top'],
                'right' => (int) $donnees['marges']['right'],
                'bottom' => (int) $donnees['marges']['bottom'],
                'left' => (int) $donnees['marges']['left'],
                'header' => (int) $donnees['marges']['header'],
                'footer' => (int) $donnees['marges']['footer'],
            ],
            'tableau' => [
                'style' => $donnees['tableau']['style'],
                'header_couleur' => mb_strtoupper($donnees['tableau']['header_couleur']),
                'header_texte' => mb_strtoupper($donnees['tableau']['header_texte']),
                'bordure' => $request->boolean('tableau.bordure'),
            ],
        ];

        return [
            'name' => $donnees['name'],
            'description' => $donnees['description'] ?? '',
            'is_public' => $request->boolean('is_public'),
            'params' => $params,
        ];
    }
}
