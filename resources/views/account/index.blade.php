@extends('layouts.app')

@section('title', 'Mon compte')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Espace personnel</span>
            <h1>Mon compte</h1>
            <p>
                Gérez vos crédits IA, votre abonnement et l'historique de vos achats.
                <strong>1 crédit = 1 FCFA</strong> — achat à partir de {{ number_format(config('kpay.min_amount', 500), 0, ',', ' ') }} FCFA.
            </p>
        </div>
        <div class="credits-badge {{ $balance < 100 ? 'low' : '' }}" title="Solde de crédits — 1 crédit = 1 FCFA">
            <i data-lucide="coins" style="width:14px;height:14px"></i>
            {{ number_format($balance, 0, ',', ' ') }} crédits
        </div>
    </div>

    <div class="grid" style="grid-template-columns:1fr;gap:1.25rem">

        {{-- Statistiques du mois : solde + quotas (exigence D) --}}
        <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
            <div class="stat-item">
                <span class="stat-label">Solde de crédits</span>
                <span class="stat-value">{{ number_format($balance, 0, ',', ' ') }}</span>
                <span class="stat-hint">1 crédit = 1 FCFA</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Documents traités (mois {{ $quotaStatus['month'] }})</span>
                <span class="stat-value">
                    {{ $quotaStatus['deterministic']['used'] }}
                    <small style="font-size:.55em;color:var(--color-text-muted)">/ {{ $quotaStatus['deterministic']['unlimited'] ? '∞' : $quotaStatus['deterministic']['quota'] }}</small>
                </span>
                <div class="progress" style="margin-top:.5rem">
                    <div class="progress-bar" style="width:{{ $quotaStatus['deterministic']['unlimited'] ? 100 : min(100, ($quotaStatus['deterministic']['quota'] > 0 ? $quotaStatus['deterministic']['used'] / $quotaStatus['deterministic']['quota'] * 100 : 0)) }}%"></div>
                </div>
                <span class="stat-hint">
                    @if ($quotaStatus['deterministic']['unlimited'])
                        Illimité
                    @else
                        {{ $quotaStatus['deterministic']['remaining'] }} restant(s)
                    @endif
                </span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Traitements IA (mois {{ $quotaStatus['month'] }})</span>
                <span class="stat-value">
                    {{ $quotaStatus['ai']['used'] }}
                    <small style="font-size:.55em;color:var(--color-text-muted)">/ {{ $quotaStatus['ai']['unlimited'] ? '∞' : $quotaStatus['ai']['quota'] }}</small>
                </span>
                <div class="progress" style="margin-top:.5rem">
                    <div class="progress-bar" style="width:{{ $quotaStatus['ai']['unlimited'] ? 100 : min(100, ($quotaStatus['ai']['quota'] > 0 ? $quotaStatus['ai']['used'] / $quotaStatus['ai']['quota'] * 100 : 0)) }}%"></div>
                </div>
                <span class="stat-hint">
                    @if ($quotaStatus['ai']['unlimited'])
                        Illimité
                    @elseif ($quotaStatus['ai']['remaining'] > 0)
                        {{ $quotaStatus['ai']['remaining'] }} restant(s)
                    @else
                        <span style="color:var(--color-correction)">Épuisé — plan supérieur ou crédits</span>
                    @endif
                </span>
            </div>
        </div>

        <div class="grid" style="grid-template-columns:1fr;gap:1.25rem">

            {{-- Abonnement actif + achat --}}
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem">
                {{-- Abonnement actif --}}
                <div class="card">
                    <h2 class="card-title" style="margin-bottom:.5rem">Abonnement actif</h2>
                    @if ($subscription && $subscription->isActiveAt())
                        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem">
                            <span class="badge badge-success"><i data-lucide="badge-check" style="width:12px;height:12px"></i> Actif</span>
                            <h3 style="font-family:var(--font-display);font-size:1.25rem;margin:0">{{ $subscription->plan->name }}</h3>
                        </div>
                        <p style="font-size:.85rem;color:var(--color-text-muted)">
                            @if ($subscription->ends_at)
                                Valide jusqu'au {{ $subscription->ends_at->format('d/m/Y') }}
                            @else
                                Illimité (Entreprises)
                            @endif
                        </p>
                        <ul style="margin-top:.6rem">
                            @foreach (($subscription->plan->features ?? []) as $feature)
                                <li style="display:flex;gap:.4rem;font-size:.82rem;margin:.2rem 0">
                                    <i data-lucide="check" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p style="font-size:.9rem;color:var(--color-text-muted);margin-bottom:.5rem">
                            Aucun abonnement actif — plan Gratuit : 5 documents déterministes / mois, 0 traitement IA.
                        </p>
                        <a href="#plans" class="btn btn-secondary btn-sm">
                            <i data-lucide="sparkles" style="width:14px;height:14px"></i> Découvrir les plans
                        </a>
                    @endif
                </div>

                {{-- Formulaire d'achat --}}
                <div class="card">
                    <h2 class="card-title" style="margin-bottom:.5rem">Acheter des crédits</h2>
                    <form action="{{ route('credits.purchase') }}" method="POST">
                        @csrf
                        <div class="form-group">
                            <label for="amount">Montant (FCFA) — minimum {{ number_format(config('kpay.min_amount', 500), 0, ',', ' ') }}</label>
                            <input type="number" name="amount" id="amount" required min="{{ config('kpay.min_amount', 500) }}"
                                   step="100" max="500000" placeholder="1 000"
                                   class="form-control" value="{{ old('amount', 1000) }}">
                            @error('amount')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i data-lucide="credit-card" style="width:15px;height:15px"></i> Payer
                        </button>
                        <p class="chat-disclaimer" style="margin-top:.5rem">
                            Paiement sécurisé via KPay (carte bancaire, mobile money : Orange Money, MTN MoMo…).
                        </p>
                    </form>
                </div>

                {{-- Chat IA --}}
                <a href="{{ route('chat.index') }}" class="card" style="text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:.4rem">
                    <span style="display:flex;align-items:center;gap:.5rem;font-weight:600">
                        <i data-lucide="message-circle" style="width:17px;height:17px;color:var(--color-primary)"></i>
                        Assistant IA
                    </span>
                    <span style="font-size:.82rem;color:var(--color-text-muted)">Posez vos questions, coût affiché avant envoi — outils actionnables (page de garde, reconstruction, web, images).</span>
                    <span class="btn btn-ghost btn-sm" style="align-self:flex-start;margin-top:.3rem">Ouvrir →</span>
                </a>
            </div>

            {{-- Historique des transactions --}}
            <div class="card">
                <h2 class="card-title" style="margin-bottom:.5rem">Historique des transactions</h2>

                @if ($transactions->isEmpty())
                    <p style="text-align:center;padding:2rem 0;color:var(--color-text-muted)">
                        Aucune transaction pour le moment.
                    </p>
                @else
                    <div class="table-wrap" style="padding:0">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th style="text-align:right">Montant</th>
                                    <th style="text-align:right">Solde</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $tx)
                                    <tr>
                                        <td class="mono" style="white-space:nowrap">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                                        <td>
                                            @if ($tx->type === 'purchase')
                                                <span class="badge badge-success"><i data-lucide="plus" style="width:11px;height:11px"></i> Achat</span>
                                            @elseif ($tx->type === 'refund')
                                                <span class="badge badge-info"><i data-lucide="rotate-ccw" style="width:11px;height:11px"></i> Remboursement</span>
                                            @elseif ($tx->type === 'bonus')
                                                <span class="badge badge-warning"><i data-lucide="gift" style="width:11px;height:11px"></i> Bonus</span>
                                            @else
                                                <span class="badge"><i data-lucide="zap" style="width:11px;height:11px"></i> Utilisation</span>
                                            @endif
                                        </td>
                                        <td>{{ $tx->description ?: $tx->type }}</td>
                                        <td style="text-align:right;font-weight:600;color:{{ $tx->amount >= 0 ? 'var(--color-secondary)' : 'var(--color-ink)' }}">
                                            {{ $tx->amount >= 0 ? '+' : '' }}{{ number_format($tx->amount, 0, ',', ' ') }}
                                        </td>
                                        <td style="text-align:right" class="mono">{{ number_format($tx->balance_after, 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Plans d'abonnement (nouveaux tarifs / quotas) --}}
            <div id="plans" class="card">
                <h2 class="card-title" style="margin-bottom:.3rem">Plans d'abonnement mensuels</h2>
                <p style="font-size:.82rem;color:var(--color-text-muted);margin-bottom:1rem">
                    Les quotas sont réinitialisés chaque mois. Traitements IA facturés en crédits en plus de l'abonnement.
                </p>

                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem">
                    @foreach ($plans as $plan)
                        <div class="card" style="display:flex;flex-direction:column;gap:.35rem;border:1px solid var(--color-border);
                            {{ $subscription && $subscription->plan_id === $plan->id ? 'border-color:var(--color-primary);box-shadow:0 0 0 1px var(--color-primary)' : '' }}">
                            <div style="display:flex;align-items:center;justify-content:space-between">
                                <h3 style="font-family:var(--font-display);font-size:1.1rem;margin:0">{{ $plan->name }}</h3>
                                @if ($subscription && $subscription->plan_id === $plan->id)
                                    <span class="badge badge-success">Actuel</span>
                                @endif
                            </div>
                            <p style="font-size:1.35rem;font-weight:700;color:var(--color-primary);margin:0">
                                @if ($plan->price_fcfa > 0)
                                    {{ number_format($plan->price_fcfa, 0, ',', ' ') }} <small style="font-size:.6em;color:var(--color-text-muted)">FCFA/mois</small>
                                @else
                                    <small style="font-size:.6em;color:var(--color-text-muted)">Sur devis</small>
                                @endif
                            </p>
                            <p style="font-size:.8rem;color:var(--color-text-muted);flex:1">{{ $plan->description }}</p>
                            <ul style="margin-top:.3rem">
                                @if ($plan->quota_deterministic !== null)
                                    <li style="display:flex;gap:.4rem;font-size:.8rem;margin:.15rem 0">
                                        <i data-lucide="file-text" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                        {{ $plan->quota_deterministic }} document(s) déterministe(s) / mois
                                    </li>
                                @else
                                    <li style="display:flex;gap:.4rem;font-size:.8rem;margin:.15rem 0">
                                        <i data-lucide="infinity" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                        Documents déterministes illimités
                                    </li>
                                @endif
                                @if ($plan->quota_ai !== null)
                                    <li style="display:flex;gap:.4rem;font-size:.8rem;margin:.15rem 0">
                                        <i data-lucide="bot" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                        {{ $plan->quota_ai }} traitement(s) IA / mois
                                    </li>
                                @else
                                    <li style="display:flex;gap:.4rem;font-size:.8rem;margin:.15rem 0">
                                        <i data-lucide="infinity" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                        Traitements IA illimités
                                    </li>
                                @endif
                                @foreach (($plan->features ?? []) as $feature)
                                    <li style="display:flex;gap:.4rem;font-size:.8rem;margin:.15rem 0">
                                        <i data-lucide="check" style="width:13px;height:13px;color:var(--color-secondary);flex-shrink:0;margin-top:.15rem"></i>
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>
                            @unless ($subscription && $subscription->plan_id === $plan->id)
                                <a href="{{ route('feedback.form') }}" class="btn btn-ghost btn-sm" style="margin-top:.5rem">Contactez-nous</a>
                            @endunless
                        </div>
                    @endforeach
                </div>
                <p class="chat-disclaimer" style="margin-top:1rem">
                    Abonnement Entreprises sur devis. Souscription et renouvellement via notre équipe (formulaire de contact).
                    Le plan Gratuit (par défaut) offre 5 documents déterministes / mois et 0 traitement IA.
                </p>
            </div>
        </div>
    </div>
@endsection

