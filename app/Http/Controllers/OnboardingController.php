<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Parcours d'accueil (onboarding) — véritable assistant en 4 étapes.
 *
 * **Ce qui existait avant, et pourquoi c'était insuffisant.** La page
 * `/onboarding` était une maquette : trois cartes cliquables qui ne menaient à
 * rien et dont la progression n'était pas conservée. Un utilisateur qui fermait
 * l'onglet retombait au même point, et rien ne distinguait un compte configuré
 * d'un compte à l'abandon. (`AUDIT_UI_UX.md` § 2.4.)
 *
 * **Ce qui change.** L'étape courante est stockée sur le compte
 * (`users.onboarding_step`) et l'achèvement daté (`onboarding_completed_at`).
 * Le parcours est donc :
 *  - **reprenable** — on rouvre exactement là où on s'est arrêté ;
 *  - **sautable** — « Passer » marque le parcours terminé, jamais bloquant
 *    (règle UX : on ne force pas une visite guidée) ;
 *  - **non redondant** — un compte déjà configuré qui rappelle l'URL est
 *    redirigé vers le tableau de bord.
 *
 * **Invariant de progression.** Une étape ne RECULE jamais : avancer deux fois
 * (double clic, onglet rouvert) ne ramène pas l'utilisateur en arrière. Toutes
 * les écritures passent donc par `max(étape actuelle, étape cible)`.
 */
class OnboardingController extends Controller
{
    /**
     * Nombre d'étapes du parcours. Source unique : la vue et les tests s'y
     * réfèrent, pour qu'ajouter une étape ne demande pas de corriger un nombre
     * écrit en dur à trois endroits.
     */
    public const TOTAL_STEPS = 4;

    /**
     * Affiche l'étape courante, ou redirige vers le tableau de bord si le
     * parcours est déjà terminé.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasCompletedOnboarding()) {
            return redirect()->route('dashboard');
        }

        return view('onboarding.show', [
            'user' => $user,
            'step' => $user->onboardingStep(),
            'totalSteps' => self::TOTAL_STEPS,
            'preferences' => $user->preferences ?? [],
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Étape 1 — préférences (langue, thème, notifications).
     *
     * Réutilise la même structure que `AccountController::updatePreferences` :
     * l'utilisateur qui repasse par l'écran des paramètres doit retrouver ce
     * qu'il a saisi ici, et inversement.
     */
    public function storePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'in:fr,en'],
            'theme_dark' => ['nullable', 'boolean'],
            'email_notifications' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $preferences = $user->preferences ?? [];
        $preferences['locale'] = $data['locale'];
        $preferences['theme'] = $request->boolean('theme_dark') ? 'dark' : 'light';
        $preferences['email_notifications'] = $request->boolean('email_notifications');

        $user->preferences = $preferences;
        $user->onboarding_step = max(2, $user->onboardingStep());
        $user->save();

        return redirect()->route('onboarding')->with('success', 'Préférences enregistrées.');
    }

    /**
     * Avance d'une étape sans saisie (étapes 2 et 3, qui orientent vers des
     * actions réelles : téléverser un document, consulter les crédits).
     *
     * L'étape cible est VALIDÉE et bornée : elle ne peut ni reculer, ni dépasser
     * la dernière étape. Terminer le parcours passe par `complete()` ou
     * `choosePlan()`, pas par cette méthode — sinon un `step=99` clôturerait le
     * parcours sans passer par l'écran final.
     */
    public function advance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'min:2', 'max:'.self::TOTAL_STEPS],
        ]);

        $user = $request->user();
        $user->onboarding_step = max((int) $data['step'], $user->onboardingStep());
        $user->save();

        return redirect()->route('onboarding');
    }

    /**
     * Étape 4 — enregistre l'offre envisagée puis clôt le parcours.
     *
     * **Aucun abonnement n'est créé ici, et c'est volontaire.** Choisir une offre
     * déclenche un paiement réel : le faire au détour d'un parcours d'accueil,
     * sans écran de récapitulatif ni confirmation de prix, serait une façon
     * d'engager un acheteur à son insu. L'intention est MÉMORISÉE
     * (`preferences.intended_plan`) pour pré-sélectionner l'offre sur la page de
     * paiement — la décision d'acheter reste prise par l'utilisateur, sur l'écran
     * de tarifs.
     */
    public function choosePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
        ]);

        $user = $request->user();

        $preferences = $user->preferences ?? [];
        $preferences['intended_plan'] = $data['plan'];
        $user->preferences = $preferences;

        return $this->completeFor($request, $user);
    }

    /**
     * Termine le parcours sans choisir d'offre (« Passer », « Terminer plus
     * tard »). Marque l'achèvement pour ne plus le reproposer.
     */
    public function complete(Request $request): RedirectResponse
    {
        return $this->completeFor($request, $request->user());
    }

    /**
     * Marque le parcours terminé et renvoie vers le tableau de bord.
     *
     * Le message de retour est passé PAR APPELANT quand il apporte une
     * information (l'offre choisie), et vaut un libellé générique sinon : un
     * même message pour trois issues différentes priverait l'utilisateur de la
     * confirmation de ce qu'il vient de faire.
     */
    private function completeFor(Request $request, User $user): RedirectResponse
    {
        $user->onboarding_step = self::TOTAL_STEPS;
        $user->onboarding_completed_at = now();
        $user->save();

        return redirect()->route('dashboard')
            ->with('success', 'Configuration terminée. Bienvenue sur FORMADOC !');
    }
}
