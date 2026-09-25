@extends('layouts.admin')

@section('title', 'Nouveau lien de paiement')

@section('content')
    <div class="page-head">
        <span class="eyebrow">
            <a href="{{ route('admin.payment-links.index') }}" style="color:inherit">Liens de paiement</a>
        </span>
        <h1>Nouveau lien</h1>
        <p>Génère une adresse à transmettre au client pour qu'il règle un montant précis.</p>
    </div>

    @if ($errors->any())
        <div class="banner banner-danger">
            <i data-lucide="alert-circle"></i>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.payment-links.store') }}" style="max-width:760px">
        @csrf

        <section class="card" style="margin-bottom:1.5rem">
            <h2 class="card-title" style="margin-bottom:1.25rem">Montant et objet</h2>

            <div class="form-group">
                <label for="amount_fcfa">Montant (FCFA)</label>
                <input type="number" id="amount_fcfa" name="amount_fcfa" required
                       min="{{ $montantMin }}" max="5000000" step="1"
                       value="{{ old('amount_fcfa') }}" class="form-control">
                <p class="form-hint">
                    Minimum {{ number_format($montantMin, 0, ',', ' ') }} FCFA (réglage « Montant minimum
                    d'achat »). 1 crédit = 1 FCFA : le client reçoit autant de crédits que de francs réglés.
                </p>
            </div>

            <div class="form-group">
                <label for="label">Objet (interne)</label>
                <input type="text" id="label" name="label" maxlength="191"
                       value="{{ old('label') }}" class="form-control"
                       placeholder="Facture client SARL, inscription BTS…">
                <p class="form-hint">
                    Sert à retrouver le lien dans la liste. Jamais montré au client.
                </p>
            </div>

            <div class="form-group">
                <label for="description">Description affichée au client</label>
                <input type="text" id="description" name="description" maxlength="500"
                       value="{{ old('description') }}" class="form-control"
                       placeholder="Mise en forme de mémoire — Université de Douala">
                <p class="form-hint">
                    Visible sur la page de règlement. Un lien sans explication n'inspire pas confiance.
                </p>
            </div>

            <div class="form-group">
                <label for="ttl_jours">Validité (jours)</label>
                <input type="number" id="ttl_jours" name="ttl_jours" min="1" max="365" step="1"
                       value="{{ old('ttl_jours', $ttlDefaut) }}" class="form-control">
                <p class="form-hint">
                    Passé ce délai, le lien affiche une page d'expiration explicite. Valeur par défaut
                    issue du réglage « Validité d'un lien de paiement ».
                </p>
            </div>
        </section>

        <section class="card" style="margin-bottom:1.5rem">
            <h2 class="card-title" style="margin-bottom:.3rem">Mode de règlement</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.25rem">
                Le mode détermine qui constate le paiement.
            </p>

            <div style="display:flex;flex-direction:column;gap:1rem">
                <label style="display:flex;gap:.75rem;align-items:flex-start;cursor:pointer">
                    <input type="radio" name="mode" value="online" @checked(old('mode', 'online') === 'online') style="margin-top:.25rem">
                    <span>
                        <strong style="font-size:.9rem">En ligne — passerelle KPay</strong>
                        <span style="display:block;font-size:.82rem;color:var(--color-text-secondary);margin-top:.2rem">
                            Le client ouvre la page KPay, choisit son moyen de paiement (mobile money,
                            carte, PayPal) et règle immédiatement. Les crédits sont versés à la
                            confirmation de l'opérateur. <strong>Un compte destinataire est obligatoire</strong>
                            : sans lui, les crédits n'auraient nulle part où être versés.
                        </span>
                    </span>
                </label>

                <label style="display:flex;gap:.75rem;align-items:flex-start;cursor:pointer">
                    <input type="radio" name="mode" value="offline" @checked(old('mode') === 'offline') style="margin-top:.25rem">
                    <span>
                        <strong style="font-size:.9rem">Hors ligne — encaissement constaté</strong>
                        <span style="display:block;font-size:.82rem;color:var(--color-text-secondary);margin-top:.2rem">
                            Le client règle par un autre canal (espèces, virement, mobile money direct)
                            et vous enregistrez le paiement depuis ce lien, avec sa référence. La page
                            publique explique alors qu'aucun règlement en ligne n'est attendu.
                            <strong>Indispensable quand les opérateurs sont en maintenance</strong> : un
                            dispositif qui ne sait encaisser qu'en ligne perd la vente.
                        </span>
                    </span>
                </label>
            </div>
        </section>

        <section class="card" style="margin-bottom:1.5rem">
            <h2 class="card-title" style="margin-bottom:1.25rem">Destinataire</h2>

            <div class="form-group">
                <label for="user_id">Compte FORMADOC</label>
                <select id="user_id" name="user_id" class="form-control">
                    <option value="">— Aucun (client externe) —</option>
                    @foreach ($utilisateurs as $utilisateur)
                        <option value="{{ $utilisateur->id }}" @selected(old('user_id') == $utilisateur->id)>
                            {{ $utilisateur->email }} ({{ $utilisateur->name }})
                        </option>
                    @endforeach
                </select>
                <p class="form-hint">
                    Obligatoire pour un lien réglable en ligne : c'est le compte qui recevra les crédits.
                </p>
            </div>

            <div class="form-group">
                <label for="customer_label">Nom du client (facultatif)</label>
                <input type="text" id="customer_label" name="customer_label" maxlength="191"
                       value="{{ old('customer_label') }}" class="form-control">
                <p class="form-hint">Pour un client sans compte. Aide à rapprocher un lien d'un règlement reçu.</p>
            </div>

            <div class="form-group">
                <label for="customer_email">Adresse e-mail du client (facultatif)</label>
                <input type="email" id="customer_email" name="customer_email" maxlength="191"
                       value="{{ old('customer_email') }}" class="form-control">
                <p class="form-hint">
                    Transmise à la passerelle : elle associe le reçu KPay au bon client. Aucun e-mail
                    n'est envoyé par FORMADOC depuis cet écran — c'est vous qui transmettez le lien.
                </p>
            </div>
        </section>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary">
                <i data-lucide="link" style="width:15px;height:15px"></i> Créer le lien
            </button>
            <a href="{{ route('admin.payment-links.index') }}" class="btn btn-secondary">Annuler</a>
        </div>
    </form>
@endsection
