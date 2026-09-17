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

            {{-- Solde + Abonnement actif (credit-grid, conforme formadoc-template.html) --}}
            <div class="credit-grid" style="margin-bottom:1.75rem;">
                <div class="card">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:600;">Solde de crédits</span>
                        <span class="badge badge-success mono">{{ number_format($balance, 0, ',', ' ') }}</span>
                    </div>
                    <div style="margin-top:.3rem;font-size:.85rem;color:var(--color-text-muted);">1 crédit ≈ 1 FCFA</div>
                    <button class="btn btn-primary btn-sm" style="margin-top:1.1rem;" id="showPurchaseModal">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v12"/><path d="M8 10h8"/></svg>
                        Acheter des crédits
                    </button>
                </div>
                <div class="card">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:600;">Abonnement actif</span>
                        @if ($subscription && $subscription->isActiveAt())
                            <span class="badge badge-info">{{ $subscription->plan->name }}</span>
                        @else
                            <span class="badge">Gratuit</span>
                        @endif
                    </div>
                    <div style="margin-top:.3rem;font-size:.85rem;color:var(--color-text-muted);">
                        @if ($subscription && $subscription->isActiveAt() && $subscription->auto_renew && $subscription->ends_at)
                            Renouvellement automatique le {{ $subscription->ends_at->format('d/m/Y') }}
                        @elseif ($subscription && $subscription->isActiveAt() && !$subscription->auto_renew && $subscription->ends_at)
                            Actif jusqu'au {{ $subscription->ends_at->format('d/m/Y') }} — renouvellement désactivé
                        @elseif ($subscription && $subscription->isActiveAt())
                            Valide jusqu'au {{ $subscription->ends_at ? $subscription->ends_at->format('d/m/Y') : '— (illimité)' }}
                        @else
                            Plan gratuit : 5 documents déterministes / mois, 0 traitement IA.
                        @endif
                    </div>
                    <div style="display:flex;gap:.5rem;margin-top:1.1rem;flex-wrap:wrap;">
                        @if ($subscription && $subscription->isActiveAt())
                            <a href="{{ route('subscriptions.checkout', $subscription->plan->slug) }}" class="btn btn-secondary btn-sm">Changer de plan</a>
                            @if ($subscription->auto_renew)
                                <button class="btn btn-ghost btn-sm" id="showCancelSubModal">Annuler l'abonnement</button>
                            @endif
                        @else
                            <a href="#plans" class="btn btn-secondary btn-sm">Découvrir les plans</a>
                        @endif
                        <a href="{{ route('invoices.index') }}" class="btn btn-ghost btn-sm">Mes factures</a>
                    </div>
                </div>
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

            {{-- Plans d'abonnement (plan-grid, conforme formadoc-template.html) --}}
            <div id="plans" style="margin-bottom:1.75rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:0;">Plans d'abonnement</h2>
                    {{-- Sélecteur de devise (Q5b) : l'affichage et le checkout suivent la devise choisie --}}
                    <form method="GET" action="{{ url()->current() }}" style="display:flex;align-items:center;gap:.5rem">
                        <label for="currency-select" style="font-size:.8rem;color:var(--color-text-muted)">Devise :</label>
                        <select name="currency" id="currency-select" class="form-control" style="width:auto;padding:.35rem .75rem;font-size:.85rem"
                                onchange="this.form.submit()">
                            @foreach (config('billing.currencies', []) as $code => $cfg)
                                <option value="{{ $code }}" {{ $currency === $code ? 'selected' : '' }}>
                                    {{ $code }} ({{ $cfg['symbol'] }})
                                </option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <p style="font-size:.82rem;color:var(--color-text-muted);margin-bottom:1rem;margin-top:.5rem">
                    Les quotas sont réinitialisés chaque mois. Traitements IA facturés en crédits en plus de l'abonnement.
                </p>

                <div class="plan-grid">
                    {{-- Plan Gratuit (pas de ligne en base — plan par défaut, conforme template) --}}
                    <div class="plan-card {{ (!$subscription || !$subscription->isActiveAt()) ? 'current' : '' }}">
                        <div class="plan-name">Gratuit</div>
                        <div class="price mono">0 <span>FCFA/mois</span></div>
                        <div class="plan-desc">5 documents déterministes · 0 traitement IA</div>
                        <ul class="plan-features">
                            <li><i data-lucide="check" style="width:13px;height:13px"></i> 5 documents traités / mois</li>
                            <li><i data-lucide="check" style="width:13px;height:13px"></i> Mise en forme déterministe (hors-ligne)</li>
                            <li><i data-lucide="check" style="width:13px;height:13px"></i> Gabarits de mise en forme publics</li>
                            <li><i data-lucide="minus" style="width:13px;height:13px"></i> Chat IA au coût réel (crédits)</li>
                        </ul>
                        @if (!$subscription || !$subscription->isActiveAt())
                            <button class="btn btn-secondary btn-sm" disabled>Plan actuel</button>
                        @else
                            <form action="{{ route('subscriptions.cancel') }}" method="POST" style="margin-top:auto">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm">Rétrograder</button>
                            </form>
                        @endif
                    </div>

                    @foreach ($plans as $plan)
                        @php
                            $isCurrent = $subscription && $subscription->plan_id === $plan->id;
                            $isPopular = $plan->slug === 'premium';
                            $isFree = $plan->price_fcfa <= 0 && $plan->slug !== 'enterprise';
                            $isEnterprise = $plan->slug === 'enterprise';
                        @endphp
                        <div class="plan-card {{ $isCurrent ? 'current' : '' }} {{ $isPopular && !$isCurrent ? 'popular' : '' }}">
                            @if ($isPopular && !$isCurrent)
                                <span class="plan-tag">Populaire</span>
                            @endif
                            <div class="plan-name">{{ $plan->name }}</div>
                            @if ($isEnterprise)
                                <div class="price">Sur devis</div>
                            @else
                                <div class="price mono">{{ $prices[$plan->slug] ?? number_format($plan->price_fcfa, 0, ',', ' ') }} <span>/mois</span></div>
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
                            @if (!empty($plan->features))
                                <ul class="plan-features">
                                    @foreach ($plan->features as $feature)
                                        <li><i data-lucide="check" style="width:13px;height:13px"></i> {{ $feature }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($isCurrent)
                                <button class="btn btn-secondary btn-sm" disabled>Plan actuel</button>
                            @elseif ($isFree)
                                <form action="{{ route('subscriptions.cancel') }}" method="POST" style="margin-top:auto">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm" {{ $subscription ? '' : 'disabled' }}>Rétrograder</button>
                                </form>
                            @elseif ($isEnterprise)
                                <a href="{{ route('feedback.form') }}" class="btn btn-secondary btn-sm">Nous contacter</a>
                            @else
                                <a href="{{ route('subscriptions.checkout', [$plan->slug, 'currency' => $currency]) }}" class="btn {{ $isPopular ? 'btn-primary' : 'btn-secondary' }} btn-sm">
                                    {{ $subscription ? 'Passer à ' . $plan->name : 'Choisir ' . $plan->name }}
                                </a>
                            @endif
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

    {{-- Modale : acheter des crédits (conforme formadoc-template.html) --}}
    <div class="modal-overlay" id="purchaseModal" role="dialog" aria-modal="true" aria-labelledby="purchaseModalTitle">
        <div class="modal">
            <div class="modal-header"><h3 id="purchaseModalTitle">Acheter des crédits</h3><button class="modal-close" id="closePurchaseModal" aria-label="Fermer">×</button></div>
            <form action="{{ route('credits.purchase') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="creditAmount">Montant (FCFA) — minimum {{ number_format(config('kpay.min_amount', 500), 0, ',', ' ') }}</label>
                    <input class="form-control" type="number" name="amount" id="creditAmount" value="1000"
                           min="{{ config('kpay.min_amount', 500) }}" step="100" max="500000" required />
                    @error('amount')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.4rem;">
                    <button type="button" class="btn btn-secondary btn-sm quick-amount" data-amount="500">500</button>
                    <button type="button" class="btn btn-secondary btn-sm quick-amount" data-amount="1000">1 000</button>
                    <button type="button" class="btn btn-secondary btn-sm quick-amount" data-amount="2000">2 000</button>
                    <button type="button" class="btn btn-secondary btn-sm quick-amount" data-amount="5000">5 000</button>
                </div>
                <div style="display:flex;gap:.6rem;margin-bottom:1.4rem;justify-content:center;font-size:.85rem;color:var(--color-text-secondary);flex-wrap:wrap;">
                    <span>Carte bancaire</span><span>·</span><span>KPay</span><span>·</span><span>Orange Money</span><span>·</span><span>MTN MoMo</span>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="confirmPurchase">Payer maintenant</button>
            </form>
        </div>
    </div>

    {{-- Modale : annuler l'abonnement (conforme formadoc-template.html) --}}
    @if ($subscription && $subscription->isActiveAt() && $subscription->auto_renew)
        <div class="modal-overlay" id="cancelSubModal" role="dialog" aria-modal="true" aria-labelledby="cancelSubTitle">
            <div class="modal">
                <div class="modal-header"><h3 id="cancelSubTitle">Annuler l'abonnement</h3><button class="modal-close" id="closeCancelSubModal" aria-label="Fermer">×</button></div>
                <div class="banner banner-warning" style="margin-bottom:1.1rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                    <div>Votre abonnement {{ $subscription->plan->name }} restera actif jusqu'au {{ $subscription->ends_at->format('d/m/Y') }} (fin de la période déjà payée), puis basculera automatiquement vers le plan Gratuit.</div>
                </div>
                <form action="{{ route('subscriptions.cancel') }}" method="POST" style="display:flex;gap:.6rem;">
                    @csrf
                    <button type="button" class="btn btn-secondary btn-block" id="keepSubscription">Garder mon abonnement</button>
                    <button type="submit" class="btn btn-danger btn-block" id="confirmCancelSub">Confirmer l'annulation</button>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    function openModal(el) { el.classList.add('open'); }
    function closeModal(el) { el.classList.remove('open'); }

    // Modale achat de crédits
    const purchaseModal = document.getElementById('purchaseModal');
    const showPurchaseBtn = document.getElementById('showPurchaseModal');
    if (showPurchaseBtn && purchaseModal) {
        showPurchaseBtn.addEventListener('click', () => openModal(purchaseModal));
        document.getElementById('closePurchaseModal').addEventListener('click', () => closeModal(purchaseModal));
        purchaseModal.addEventListener('click', e => { if (e.target === purchaseModal) closeModal(purchaseModal); });
        document.querySelectorAll('.quick-amount').forEach(btn => btn.addEventListener('click', function () {
            document.getElementById('creditAmount').value = this.dataset.amount;
        }));
    }

    // Modale annulation d'abonnement
    const cancelSubModal = document.getElementById('cancelSubModal');
    const showCancelSubBtn = document.getElementById('showCancelSubModal');
    if (showCancelSubBtn && cancelSubModal) {
        showCancelSubBtn.addEventListener('click', () => openModal(cancelSubModal));
        document.getElementById('closeCancelSubModal').addEventListener('click', () => closeModal(cancelSubModal));
        cancelSubModal.addEventListener('click', e => { if (e.target === cancelSubModal) closeModal(cancelSubModal); });
        document.getElementById('keepSubscription').addEventListener('click', () => closeModal(cancelSubModal));
    }
</script>
@endpush

