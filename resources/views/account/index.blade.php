@extends('layouts.app')

@section('title', 'Mon compte')

@section('content')
    <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

        {{-- En-tête de page --}}
        <div class="mb-8 md:mb-12">
            <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Espace personnel</p>
            <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                Mon compte
            </h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-2xl">
                Gérez vos crédits IA, votre abonnement et l'historique de vos achats.
                <strong>1 crédit = 1 FCFA</strong> — achat à partir de {{ number_format(config('kpay.min_amount', 500), 0, ',', ' ') }} FCFA.
            </p>
        </div>

        {{-- Alertes --}}
        @if (session('success'))
            <div class="flex items-start gap-3 bg-surface-container-lowest border border-outline-variant rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-primary">check_circle</span>
                <p class="text-body-md">{{ session('success') }}</p>
            </div>
        @endif
        @if (session('error'))
            <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-error">error</span>
                <p class="text-body-md text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif
        @if (session('info'))
            <div class="flex items-start gap-3 bg-secondary-container border border-outline-variant rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-secondary">info</span>
                <p class="text-body-md text-on-secondary-container">{{ session('info') }}</p>
            </div>
        @endif
        @if ($errors->any())
            <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-error">error</span>
                <ul class="text-body-md text-on-error-container">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter">

            {{-- Colonne gauche : solde + achat --}}
            <div class="space-y-gutter">

                {{-- Solde de crédits --}}
                <div class="bg-primary text-on-primary rounded-xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <p class="font-label-mono text-label-mono uppercase opacity-80">Solde de crédits</p>
                        <span class="material-symbols-outlined">savings</span>
                    </div>
                    <p class="font-h1 text-h1 font-bold">{{ number_format($balance, 0, ',', ' ') }}</p>
                    <p class="font-caption text-caption opacity-80 mt-1">crédits (1 crédit = 1 FCFA)</p>
                </div>

                {{-- Abonnement actif --}}
                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
                    <p class="font-label-mono text-label-mono text-secondary uppercase mb-3">Abonnement actif</p>
                    @if ($subscription && $subscription->isActiveAt())
                        <div class="flex items-center gap-3 mb-2">
                            <span class="material-symbols-outlined text-primary">workspace_premium</span>
                            <p class="font-h2 text-h2 text-on-surface">{{ $subscription->plan->name }}</p>
                        </div>
                        <p class="font-caption text-caption text-on-surface-variant">
                            @if ($subscription->ends_at)
                                Valide jusqu'au {{ $subscription->ends_at->format('d/m/Y') }}
                            @else
                                Illimité (Entreprises)
                            @endif
                        </p>
                        <div class="mt-3 inline-flex items-center gap-1 bg-primary-fixed text-on-primary-fixed rounded-full px-3 py-1">
                            <span class="material-symbols-outlined text-[16px]">check</span>
                            <span class="font-caption text-caption">Actif</span>
                        </div>
                    @else
                        <p class="font-body-md text-body-md text-on-surface-variant mb-3">
                            Aucun abonnement actif — plan gratuit (modèles IA économiques).
                        </p>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-secondary">workspace_premium</span>
                            <a href="#plans" class="font-body-md text-body-md text-primary hover:underline">
                                Découvrir les plans
                            </a>
                        </div>
                    @endif
                </div>

                {{-- Formulaire d'achat --}}
                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
                    <p class="font-label-mono text-label-mono text-secondary uppercase mb-3">Acheter des crédits</p>
                    <form action="{{ route('credits.purchase') }}" method="POST">
                        @csrf
                        <label for="amount" class="font-caption text-caption text-on-surface-variant block mb-2">
                            Montant (FCFA) — minimum {{ number_format(config('kpay.min_amount', 500), 0, ',', ' ') }}
                        </label>
                        <div class="flex gap-3">
                            <input type="number" name="amount" id="amount" required min="{{ config('kpay.min_amount', 500) }}"
                                   step="100" max="500000" placeholder="1 000"
                                   class="w-full rounded-lg border-outline-variant bg-surface-container-low px-4 py-2.5 font-body-md text-body-md focus:border-primary focus:ring-primary"
                                   value="{{ old('amount', 1000) }}">
                            <button type="submit"
                                    class="shrink-0 inline-flex items-center gap-2 bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-fixed px-5 py-2.5 rounded-lg font-body-md font-semibold transition-colors">
                                <span class="material-symbols-outlined text-[20px]">payment</span>
                                Payer
                            </button>
                        </div>
                        <p class="font-caption text-caption text-on-surface-variant mt-2">
                            Paiement sécurisé via KPay (carte bancaire, mobile money : Orange Money, MTN MoMo…).
                        </p>
                    </form>
                </div>

                {{-- Chat IA --}}
                <a href="{{ route('chat.index') }}"
                   class="flex items-center justify-between bg-surface-container-lowest border border-outline-variant rounded-xl p-6 hover:border-primary transition-colors">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary">chat</span>
                        <div>
                            <p class="font-body-md font-semibold text-on-surface">Assistant IA</p>
                            <p class="font-caption text-caption text-on-surface-variant">Posez vos questions, coût affiché en crédits</p>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-outline">chevron_right</span>
                </a>
            </div>

            {{-- Colonne droite : historique + plans --}}
            <div class="lg:col-span-2 space-y-gutter">

                {{-- Historique des transactions --}}
                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
                    <p class="font-label-mono text-label-mono text-secondary uppercase mb-4">Historique des transactions</p>

                    @if ($transactions->isEmpty())
                        <p class="font-body-md text-body-md text-on-surface-variant py-6 text-center">
                            Aucune transaction pour le moment.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="border-b border-outline-variant">
                                        <th class="font-caption text-caption text-on-surface-variant uppercase py-2 pr-4">Date</th>
                                        <th class="font-caption text-caption text-on-surface-variant uppercase py-2 pr-4">Type</th>
                                        <th class="font-caption text-caption text-on-surface-variant uppercase py-2 pr-4">Description</th>
                                        <th class="font-caption text-caption text-on-surface-variant uppercase py-2 text-right">Montant</th>
                                        <th class="font-caption text-caption text-on-surface-variant uppercase py-2 pl-4 text-right">Solde</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($transactions as $tx)
                                        <tr class="border-b border-outline-variant/60 last:border-b-0">
                                            <td class="py-3 pr-4 font-caption text-caption text-on-surface-variant whitespace-nowrap">
                                                {{ $tx->created_at->format('d/m/Y H:i') }}
                                            </td>
                                            <td class="py-3 pr-4">
                                                <span class="inline-flex items-center gap-1 font-caption text-caption
                                                    {{ $tx->amount >= 0 ? 'text-primary' : 'text-on-surface-variant' }}">
                                                    @if ($tx->type === 'purchase')
                                                        <span class="material-symbols-outlined text-[16px]">add_circle</span> Achat
                                                    @elseif ($tx->type === 'refund')
                                                        <span class="material-symbols-outlined text-[16px]">replay</span> Remboursement
                                                    @elseif ($tx->type === 'bonus')
                                                        <span class="material-symbols-outlined text-[16px]">redeem</span> Bonus
                                                    @else
                                                        <span class="material-symbols-outlined text-[16px]">bolt</span> Utilisation
                                                    @endif
                                                </span>
                                            </td>
                                            <td class="py-3 pr-4 font-caption text-caption text-on-surface-variant">
                                                {{ $tx->description ?: $tx->type }}
                                            </td>
                                            <td class="py-3 pr-4 text-right font-body-md font-semibold
                                                {{ $tx->amount >= 0 ? 'text-primary' : 'text-on-surface' }}">
                                                {{ $tx->amount >= 0 ? '+' : '' }}{{ number_format($tx->amount, 0, ',', ' ') }}
                                            </td>
                                            <td class="py-3 pl-4 text-right font-caption text-caption text-on-surface-variant">
                                                {{ number_format($tx->balance_after, 0, ',', ' ') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Plans d'abonnement --}}
                <div id="plans" class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
                    <p class="font-label-mono text-label-mono text-secondary uppercase mb-4">Plans d'abonnement mensuels</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        @foreach ($plans as $plan)
                            <div class="border border-outline-variant rounded-xl p-5 flex flex-col
                                {{ $subscription && $subscription->plan_id === $plan->id ? 'border-primary ring-1 ring-primary' : '' }}">
                                <p class="font-body-md font-semibold text-on-surface">{{ $plan->name }}</p>
                                <p class="font-h2 text-h2 font-bold text-primary mt-2">
                                    @if ($plan->price_fcfa > 0)
                                        {{ number_format($plan->price_fcfa, 0, ',', ' ') }} <span class="font-caption text-caption text-on-surface-variant">FCFA/mois</span>
                                    @else
                                        <span class="font-caption text-caption text-on-surface-variant">Sur devis</span>
                                    @endif
                                </p>
                                <p class="font-caption text-caption text-on-surface-variant mt-2 flex-1">{{ $plan->description }}</p>
                                <ul class="mt-4 space-y-1.5">
                                    @foreach (($plan->features ?? []) as $feature)
                                        <li class="flex items-start gap-2 font-caption text-caption text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px] text-primary shrink-0">check</span>
                                            {{ $feature }}
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($subscription && $subscription->plan_id === $plan->id)
                                    <span class="mt-4 inline-flex justify-center items-center gap-1 bg-primary-fixed text-on-primary-fixed rounded-full px-3 py-1.5 font-caption text-caption">
                                        <span class="material-symbols-outlined text-[16px]">check</span> Actuel
                                    </span>
                                @else
                                    <a href="{{ route('feedback.form') }}" class="mt-4 text-center font-caption text-caption text-primary hover:underline">
                                        Contactez-nous
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant mt-4">
                        Abonnement Entreprises sur devis. Souscription et renouvellement via notre équipe (formulaire de contact).
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
