@extends('layouts.app')

@section('title', 'Finaliser l\'achat — '.$plan->name)

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Paiement sécurisé</span>
            <h1>Finaliser l'achat</h1>
            <p>
                Réglez en carte bancaire ou Mobile Money — votre abonnement {{ $plan->name }}
                sera activé sous quelques minutes après confirmation du paiement.
            </p>
        </div>
    </div>

    <div class="checkout-layout">
        <div>
            <form method="POST" action="{{ route('subscriptions.subscribe') }}" id="checkout-form">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <input type="hidden" name="currency" value="{{ $currency }}">

                <div class="checkout-section">
                    <h3>1 · Méthode de paiement</h3>
                    <div class="payment-methods">
                        <label class="payment-method active" data-method="card" role="button" tabindex="0">
                            <input type="radio" name="payment_method" value="card" checked class="pm-radio-input">
                            <span class="pm-radio"></span>
                            <span class="pm-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
                            <span class="pm-name">Carte bancaire</span>
                            <span class="pm-note">Visa · Mastercard</span>
                        </label>
                        <label class="payment-method" data-method="kpay" role="button" tabindex="0">
                            <input type="radio" name="payment_method" value="kpay" class="pm-radio-input">
                            <span class="pm-radio"></span>
                            <span class="pm-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></span>
                            <span class="pm-name">KPay</span>
                            <span class="pm-note">Portefeuille mobile</span>
                        </label>
                        <label class="payment-method" data-method="orange" role="button" tabindex="0">
                            <input type="radio" name="payment_method" value="orange" class="pm-radio-input">
                            <span class="pm-radio"></span>
                            <span class="pm-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="12" rx="2"/><path d="M6 20h12"/><path d="M12 16v4"/></svg></span>
                            <span class="pm-name">Orange Money</span>
                            <span class="pm-note">Orange Cameroun</span>
                        </label>
                        <label class="payment-method" data-method="mtn" role="button" tabindex="0">
                            <input type="radio" name="payment_method" value="mtn" class="pm-radio-input">
                            <span class="pm-radio"></span>
                            <span class="pm-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                            <span class="pm-name">MTN MoMo</span>
                            <span class="pm-note">MTN Cameroun</span>
                        </label>
                    </div>
                </div>

                <div class="checkout-section" id="cardFields">
                    <h3>2 · Informations de facturation</h3>
                    <div class="form-group"><label for="payName">Nom sur la carte</label><input class="form-control" id="payName" name="cardholder_name" value="{{ auth()->user()->name }}" /></div>
                    <div class="form-group"><label for="payNumber">Numéro de carte</label><input class="form-control" id="payNumber" name="card_number" inputmode="numeric" placeholder="1234 5678 9012 3456" /></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
                        <div class="form-group"><label for="payExp">Expiration</label><input class="form-control" id="payExp" name="card_expiry" placeholder="MM/AA" /></div>
                        <div class="form-group"><label for="payCvc">CVC</label><input class="form-control" id="payCvc" name="card_cvc" inputmode="numeric" placeholder="123" /></div>
                    </div>
                </div>

                <div class="checkout-section" id="mobileMoneyFields" style="display:none;">
                    <h3>2 · Numéro Mobile Money</h3>
                    <div class="form-group"><label for="payPhone">Numéro de téléphone</label><input class="form-control" id="payPhone" name="phone" inputmode="tel" placeholder="+225 07 00 00 00 00" /></div>
                    <div class="banner banner-warning" style="margin-bottom:0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <div>Vous recevrez une demande de validation sur votre téléphone pour confirmer le paiement.</div>
                    </div>
                </div>
            </form>
        </div>

        <aside class="checkout-summary">
            <h3>Récapitulatif</h3>
            <div class="order-line"><span>Abonnement {{ $plan->name }}</span><span class="mono">1 mois</span></div>
            @if ($currentPlan !== 'default' && $isUpgrade)
                <div class="order-line"><span>Prorata ancien plan</span><span class="mono">crédité</span></div>
            @endif
            <div class="order-line"><span>Frais de service</span><span class="mono">0 {{ $price['symbol'] }}</span></div>
            <div class="order-line total"><span>Total TTC</span><span class="mono">{{ number_format($price['amount'] / (10 ** $price['decimals']), $price['decimals'], ',', ' ') }} {{ $price['symbol'] }}</span></div>
            <button type="submit" form="checkout-form" class="btn btn-primary btn-block" id="confirmCheckout" style="margin-top:1rem;">
                Payer {{ number_format($price['amount'] / (10 ** $price['decimals']), $price['decimals'], ',', ' ') }} {{ $price['symbol'] }}
            </button>
            <p class="form-hint" style="text-align:center;margin-top:.7rem;">🔒 Paiement chiffré SSL</p>
        </aside>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Bascule Carte bancaire / Mobile Money (conforme au template)
            document.querySelectorAll('.payment-method').forEach(method => {
                method.addEventListener('click', function () {
                    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
                    this.classList.add('active');
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                    const isCard = this.dataset.method === 'card';
                    const cardFields = document.getElementById('cardFields');
                    const mobileFields = document.getElementById('mobileMoneyFields');
                    if (cardFields) cardFields.style.display = isCard ? 'block' : 'none';
                    if (mobileFields) mobileFields.style.display = isCard ? 'none' : 'block';
                });
                // Accessibilité : sélection au clavier (Enter / Espace) — audit UI/UX P1
                method.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });

            // Soumission : état chargement (conforme au template)
            const confirmBtn = document.getElementById('confirmCheckout');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    this.disabled = true;
                    this.textContent = 'Redirection vers le paiement…';
                });
            }
        });
    </script>
@endpush
