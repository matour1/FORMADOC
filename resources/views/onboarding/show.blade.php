@extends('layouts.app')

@section('title', 'Bienvenue')

@section('content')
    {{--
        Parcours d'accueil en 4 étapes, PERSISTANT.

        Chaque étape dépose son résultat sur le compte (voir OnboardingController) :
        l'utilisateur qui ferme l'onglet revient exactement là où il s'était
        arrêté, au lieu de retrouver la page au début comme sur l'ancienne
        maquette. Le stepper reflète l'avancement RÉEL (`done` / `active`), pas
        une décoration figée.
    --}}

    <div class="page-header">
        <div>
            <span class="eyebrow">Premiers pas</span>
            <h1>Bienvenue, {{ $user->name }}</h1>
            <p>Quatre étapes pour rendre FORMADOC opérationnel, sans quitter votre espace.</p>
        </div>
        {{-- « Passer » n'est jamais bloquant (règle UX : on ne force pas une visite
             guidée). Le POST marque le parcours terminé pour ne plus le reproposer. --}}
        <form method="POST" action="{{ route('onboarding.complete') }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">Passer</button>
        </form>
    </div>

    {{-- Stepper : avancement réel du parcours --}}
    <div class="flow-steps" aria-label="Étapes du parcours">
        @foreach (['Profil', 'Document', 'Crédits & IA', 'Offre'] as $index => $label)
            @php $num = $index + 1; @endphp
            <div class="flow-step {{ $step === $num ? 'active' : ($step > $num ? 'done' : '') }}"
                 @if ($step === $num) aria-current="step" @endif>
                <span class="flow-step-num">{{ $num }}</span>
                <span>{{ $label }}</span>
            </div>
            @if ($num < $totalSteps)
                <span class="flow-step-sep" aria-hidden="true"></span>
            @endif
        @endforeach
    </div>

    <p style="margin:-.5rem 0 1.25rem;color:var(--color-text-muted);font-size:.82rem">
        Étape {{ $step }} sur {{ $totalSteps }}
    </p>

    <div class="card">
        @if ($step === 1)
            {{-- Étape 1 — préférences. Mêmes champs que l'écran des paramètres,
                 pour que les deux écrans restent cohérents. --}}
            <span class="proof-stamp">Étape 1</span>
            <h2 style="font-size:1.5rem;margin:.9rem 0 .35rem;">Personnalisez votre espace</h2>
            <p style="color:var(--color-text-muted);margin-bottom:1.25rem">
                Ces réglages s'appliquent immédiatement et restent modifiables à tout moment.
            </p>

            <form method="POST" action="{{ route('onboarding.preferences') }}">
                @csrf

                <div class="form-group">
                    <label for="locale">Langue de l'interface</label>
                    <select id="locale" name="locale" class="form-control">
                        <option value="fr" @selected(($preferences['locale'] ?? 'fr') === 'fr')>Français</option>
                        <option value="en" @selected(($preferences['locale'] ?? 'fr') === 'en')>English</option>
                    </select>
                    @error('locale') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <label class="switch" style="display:flex;margin-bottom:.85rem">
                    <input type="checkbox" name="theme_dark" value="1" @checked(($preferences['theme'] ?? 'light') === 'dark')>
                    <span class="track"></span>
                    <span>Thème sombre</span>
                </label>

                <label class="switch" style="display:flex;margin-bottom:1.25rem">
                    <input type="checkbox" name="email_notifications" value="1" @checked(($preferences['email_notifications'] ?? true) === true)>
                    <span class="track"></span>
                    <span>Recevoir un e-mail quand un document est prêt</span>
                </label>

                <button type="submit" class="btn btn-primary">
                    Enregistrer et continuer
                    <i data-lucide="arrow-right" style="width:16px;height:16px"></i>
                </button>
            </form>

        @elseif ($step === 2)
            {{-- Étape 2 — premier document. Cadre l'attente AVANT le dépôt, ce qui
                 évite le cas le plus coûteux : un utilisateur qui dépose un format
                 refusé et croit l'outil en panne. --}}
            <span class="proof-stamp">Étape 2</span>
            <h2 style="font-size:1.5rem;margin:.9rem 0 .35rem;">Votre premier document</h2>
            <p style="color:var(--color-text-muted);margin-bottom:1rem">
                FORMADOC applique votre gabarit, génère le sommaire et numérote les
                figures et tableaux automatiquement.
            </p>

            <ul class="check-list" style="margin-bottom:1.25rem">
                <li>Formats acceptés : <strong>.docx</strong>, <strong>.doc</strong> et <strong>.txt</strong>, jusqu'à 50 Mo.</li>
                <li>Le document source reste inchangé : la mise en forme produit un nouveau fichier.</li>
                <li>Deux modes : déterministe (hors-ligne, inclus) ou assistance IA (crédits).</li>
            </ul>

            <div style="display:flex;gap:.7rem;flex-wrap:wrap">
                <a href="{{ route('documents.create') }}" class="btn btn-primary">
                    <i data-lucide="upload" style="width:16px;height:16px"></i> Téléverser maintenant
                </a>
                {{-- On peut avancer sans téléverser : l'utilisateur a le droit de
                     découvrir le reste du produit avant de fournir un fichier. --}}
                <form method="POST" action="{{ route('onboarding.advance') }}">
                    @csrf
                    <input type="hidden" name="step" value="3">
                    <button type="submit" class="btn btn-secondary">Plus tard, continuer</button>
                </form>
            </div>

        @elseif ($step === 3)
            {{-- Étape 3 — modèle économique, dit sans détour : le déterministe est
                 inclus, l'IA se paie en crédits. L'expliquer ici évite la surprise
                 au premier traitement IA. --}}
            <span class="proof-stamp">Étape 3</span>
            <h2 style="font-size:1.5rem;margin:.9rem 0 .35rem;">Crédits et assistance IA</h2>
            <p style="color:var(--color-text-muted);margin-bottom:1rem">
                La mise en forme déterministe est incluse dans l'offre gratuite. L'IA
                est optionnelle et facturée au coût réel.
            </p>

            <ul class="check-list" style="margin-bottom:1.25rem">
                <li><strong>1 crédit = 1 FCFA.</strong> Aucun abonnement obligatoire : rechargez par paliers, à la demande.</li>
                <li>Le coût est estimé <strong>avant</strong> chaque action IA, et le solde ajusté après.</li>
                <li>Si l'IA échoue, les crédits sont remboursés et le mode déterministe prend le relais.</li>
            </ul>

            <div class="banner banner-info" style="margin-bottom:1.25rem">
                <i data-lucide="coins"></i>
                <div>Votre solde actuel : <strong>{{ number_format($user->credits_balance, 0, ',', ' ') }} crédit(s)</strong>.</div>
            </div>

            <div style="display:flex;gap:.7rem;flex-wrap:wrap">
                <a href="{{ route('account.index') }}" class="btn btn-secondary">
                    <i data-lucide="wallet" style="width:16px;height:16px"></i> Voir les offres et crédits
                </a>
                <form method="POST" action="{{ route('onboarding.advance') }}">
                    @csrf
                    <input type="hidden" name="step" value="4">
                    <button type="submit" class="btn btn-primary">
                        Continuer
                        <i data-lucide="arrow-right" style="width:16px;height:16px"></i>
                    </button>
                </form>
            </div>

        @else
            {{-- Étape 4 — offre envisagée. AUCUN abonnement n'est créé ici : le
                 choix mémorise l'intention, le paiement se fait sur l'écran de
                 tarifs (voir OnboardingController::choosePlan). --}}
            <span class="proof-stamp">Étape 4</span>
            <h2 style="font-size:1.5rem;margin:.9rem 0 .35rem;">Choisissez votre rythme</h2>
            <p style="color:var(--color-text-muted);margin-bottom:1.25rem">
                Sélectionner une offre enregistre votre préférence. Le paiement reste
                une décision séparée, prise sur l'écran des tarifs.
            </p>

            @if ($plans->isEmpty())
                <div class="banner banner-warning" style="margin-bottom:1.25rem">
                    <i data-lucide="alert-circle"></i>
                    <div>Les offres ne sont pas encore publiées. Vous restez sur l'offre gratuite.</div>
                </div>
            @else
                <div class="plan-grid">
                    @foreach ($plans as $plan)
                        @php
                            $isPopular = $plan->slug === 'premium';
                            // Offre « sur devis » (entreprise) : un prix à 0 FCFA
                            // afficherait « 0 /mois », c'est-à-dire gratuit, à
                            // l'exact inverse du sens. La même règle est appliquée
                            // sur l'écran des tarifs, pour que les deux écrans ne
                            // racontent pas deux histoires.
                            $isEnterprise = $plan->slug === 'enterprise';
                        @endphp
                        <div class="plan-card {{ $isPopular ? 'popular' : '' }}">
                            @if ($isPopular)
                                <span class="plan-tag">Populaire</span>
                            @endif
                            <div class="plan-name">{{ $plan->name }}</div>
                            @if ($isEnterprise)
                                <div class="price">Sur devis</div>
                            @else
                                <div class="price mono">
                                    {{ number_format($plan->price_fcfa, 0, ',', ' ') }} <span>/mois</span>
                                </div>
                            @endif
                            <div class="plan-desc">
                                @if ($plan->quota_deterministic !== null)
                                    {{ $plan->quota_deterministic }} documents déterministes
                                @else
                                    Documents illimités
                                @endif
                                ·
                                @if ($plan->quota_ai !== null)
                                    {{ $plan->quota_ai }} traitements IA
                                @else
                                    IA illimitée
                                @endif
                            </div>
                            <form method="POST" action="{{ route('onboarding.plan') }}" style="margin-top:auto">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $plan->slug }}">
                                <button type="submit" class="btn btn-{{ $isPopular ? 'primary' : 'secondary' }} btn-sm btn-block">
                                    Choisir {{ $plan->name }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('onboarding.complete') }}" style="margin-top:1.25rem">
                @csrf
                <button type="submit" class="btn btn-secondary">
                    Rester sur l'offre gratuite et terminer
                </button>
            </form>
        @endif
    </div>
@endsection
