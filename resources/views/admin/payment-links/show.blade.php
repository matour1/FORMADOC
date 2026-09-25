@extends('layouts.admin')

@section('title', $lien->label ?: 'Lien de paiement #'.$lien->id)

@section('content')
    <div class="page-head">
        <span class="eyebrow">
            <a href="{{ route('admin.payment-links.index') }}" style="color:inherit">Liens de paiement</a>
            · <span class="mono">#{{ $lien->id }}</span>
        </span>
        <h1>{{ $lien->label ?: 'Lien de paiement' }}</h1>
        <p>
            {{ number_format($lien->amount_fcfa, 0, ',', ' ') }} {{ $lien->currency }}
            → {{ number_format($lien->credits, 0, ',', ' ') }} crédits.
        </p>
    </div>

    @if (session('success'))
        <div class="banner banner-success">
            <i data-lucide="check-circle"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif
    @if (session('error'))
        <div class="banner banner-danger">
            <i data-lucide="alert-circle"></i>
            <div>{{ session('error') }}</div>
        </div>
    @endif

    @if ($lien->status === 'paid')
        <div class="banner banner-success">
            <i data-lucide="badge-check"></i>
            <div>
                <strong>Lien réglé</strong>
                <div style="font-size:.85rem;margin-top:.25rem">
                    Le {{ $lien->paid_at?->format('d/m/Y à H:i') }}
                    @if ($lien->payment_reference)
                        · référence {{ $lien->payment_reference }}
                    @endif
                    @if ($lien->encaissePar)
                        · constaté par {{ $lien->encaissePar->email }}
                    @endif
                </div>
            </div>
        </div>
    @elseif ($lien->status === 'cancelled')
        <div class="banner">
            <i data-lucide="ban"></i>
            <div><strong>Lien annulé</strong> — il ne peut plus être réglé.</div>
        </div>
    @elseif ($expire)
        <div class="banner banner-warning">
            <i data-lucide="clock"></i>
            <div>
                <strong>Lien expiré</strong>
                <div style="font-size:.85rem;margin-top:.25rem">
                    Échéance : {{ $lien->expires_at?->format('d/m/Y à H:i') }}. Le client voit une page
                    d'expiration ; prolongez le lien ou créez-en un nouveau.
                </div>
            </div>
        </div>
    @endif

    <div class="split-grid">
        <div style="display:flex;flex-direction:column;gap:1.5rem">
            {{-- Adresse à transmettre --}}
            <section class="card">
                <h2 class="card-title" style="margin-bottom:.3rem">Adresse à transmettre</h2>
                <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1rem">
                    Copiez cette adresse et envoyez-la au client. Aucun e-mail n'est envoyé
                    automatiquement depuis cet écran.
                </p>

                {{-- Le jeton est dans l'URL : il ne doit pas être marqué « secret », mais
                     il est unique et suffit à ouvrir la page. C'est pourquoi il est
                     affiché en entier plutôt que masqué — l'administrateur DOIT le
                     transmettre. --}}
                <div style="display:flex;gap:.5rem;align-items:stretch;flex-wrap:wrap">
                    <input type="text" readonly value="{{ $url }}" id="lien-url"
                           class="form-control mono" style="flex:1;min-width:260px;font-size:.78rem"
                           onclick="this.select()">
                    <button type="button" class="btn btn-secondary" id="btn-copier">
                        <i data-lucide="copy" style="width:15px;height:15px"></i> Copier
                    </button>
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-secondary">
                        <i data-lucide="external-link" style="width:15px;height:15px"></i> Ouvrir
                    </a>
                </div>

                <p class="form-hint" style="margin-top:.75rem">
                    Toute personne qui possède cette adresse peut voir le montant et payer.
                    Le lien ne donne accès à aucune donnée de compte.
                </p>
            </section>

            {{-- Constatation d'un règlement hors ligne --}}
            @if ($lien->mode === 'offline' && $utilisable)
                <section class="card">
                    <h2 class="card-title" style="margin-bottom:.3rem">Enregistrer le règlement</h2>
                    <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                        À utiliser une fois l'argent effectivement reçu (espèces, virement, mobile
                        money direct). Les crédits sont versés immédiatement.
                    </p>

                    <form method="POST" action="{{ route('admin.payment-links.regler', $lien) }}">
                        @csrf

                        <div class="form-group">
                            <label for="payment_reference">Référence du règlement</label>
                            <input type="text" id="payment_reference" name="payment_reference" required
                                   maxlength="191" class="form-control"
                                   placeholder="N° de virement, ID transaction mobile money, reçu n°…">
                            <p class="form-hint">
                                Conservée avec votre identité. Sans référence, l'encaissement est
                                indistinguable d'un crédit accordé par erreur.
                            </p>
                            @error('payment_reference')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="check" style="width:15px;height:15px"></i>
                            Marquer comme réglé et créditer
                        </button>
                    </form>
                </section>
            @elseif ($lien->mode === 'online' && $utilisable)
                <section class="card">
                    <h2 class="card-title" style="margin-bottom:.3rem">Règlement en ligne</h2>
                    <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0">
                        Ce lien ouvre la passerelle KPay. Les crédits sont versés automatiquement à la
                        confirmation de l'opérateur. Aucune action n'est nécessaire de votre part.
                    </p>
                    <p class="form-hint" style="margin-top:.9rem">
                        Un lien en ligne ne se règle pas manuellement : le marquer payé à la main
                        pourrait provoquer un double versement si la confirmation de l'opérateur
                        arrive ensuite.
                    </p>
                </section>
            @endif

            {{-- Annulation et prolongation --}}
            @if ($utilisable)
                <section class="card">
                    <h2 class="card-title" style="margin-bottom:1rem">Gérer le lien</h2>

                    <form method="POST" action="{{ route('admin.payment-links.prolonger', $lien) }}"
                          style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
                        @csrf
                        <div>
                            <label for="jours" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">
                                Prolonger de
                            </label>
                            <input type="number" id="jours" name="jours" min="1" max="365" value="7"
                                   class="form-control" style="width:110px">
                        </div>
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="calendar-plus" style="width:15px;height:15px"></i> Prolonger
                        </button>
                        <p class="form-hint" style="flex-basis:100%;margin:0">
                            La prolongation s'ajoute à l'échéance actuelle si elle est encore future.
                        </p>
                    </form>

                    <form method="POST" action="{{ route('admin.payment-links.annuler', $lien) }}"
                          onsubmit="return confirm('Annuler ce lien ? Il ne pourra plus être réglé.');">
                        @csrf
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="ban" style="width:15px;height:15px"></i> Annuler le lien
                        </button>
                    </form>
                </section>
            @endif
        </div>

        {{-- Colonne latérale --}}
        <div style="display:flex;flex-direction:column;gap:1.5rem">
            <section class="card">
                <h2 class="card-title" style="margin-bottom:1rem">Détail</h2>

                <div style="display:flex;flex-direction:column;gap:.6rem;font-size:.85rem">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Montant</span>
                        <span class="tabular">{{ number_format($lien->amount_fcfa, 0, ',', ' ') }} {{ $lien->currency }}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Crédits</span>
                        <span class="tabular">{{ number_format($lien->credits, 0, ',', ' ') }}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Mode</span>
                        <span>{{ $lien->mode === 'online' ? 'En ligne (KPay)' : 'Hors ligne' }}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Créé le</span>
                        <span>{{ $lien->created_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Expire le</span>
                        <span>{{ $lien->expires_at?->format('d/m/Y H:i') ?? 'jamais' }}</span>
                    </div>
                    @if ($lien->auteur)
                        <div style="display:flex;justify-content:space-between">
                            <span style="color:var(--color-text-muted)">Créé par</span>
                            <span class="mono" style="font-size:.72rem">{{ $lien->auteur->email }}</span>
                        </div>
                    @endif
                </div>
            </section>

            <section class="card">
                <h2 class="card-title" style="margin-bottom:1rem">Destinataire</h2>

                @if ($lien->user)
                    <p style="font-size:.85rem;margin:0 0 .5rem">
                        <a href="{{ route('admin.users.show', $lien->user) }}">{{ $lien->user->name }}</a>
                    </p>
                    <p class="mono" style="font-size:.72rem;color:var(--color-text-muted);margin:0">
                        {{ $lien->user->email }}
                    </p>
                    <p class="form-hint" style="margin-top:.75rem">
                        Les crédits seront versés sur ce compte à l'encaissement.
                    </p>
                @else
                    <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0">
                        {{ $lien->customer_label ?: 'Client externe' }}
                        @if ($lien->customer_email)
                            <span class="mono" style="display:block;font-size:.72rem;color:var(--color-text-muted);margin-top:.3rem">
                                {{ $lien->customer_email }}
                            </span>
                        @endif
                    </p>
                    <p class="form-hint" style="margin-top:.75rem">
                        Aucun compte rattaché : le règlement sera constaté mais aucun crédit ne sera
                        versé automatiquement. Rattachez le lien à un compte si le client en possède un.
                    </p>
                @endif
            </section>
        </div>
    </div>

    @push('scripts')
        <script>
            // Copie du lien : `navigator.clipboard` n'existe pas hors HTTPS et sur
            // certains navigateurs anciens. Le repli par `select()` + `execCommand`
            // évite un bouton qui ne fait rien — le seul geste attendu de cet écran
            // est justement de copier l'adresse.
            document.getElementById('btn-copier')?.addEventListener('click', async () => {
                const champ = document.getElementById('lien-url');
                const bouton = document.getElementById('btn-copier');
                const original = bouton.innerHTML;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(champ.value);
                    } else {
                        champ.select();
                        document.execCommand('copy');
                    }
                    bouton.textContent = 'Copié';
                } catch (e) {
                    champ.select();
                    bouton.textContent = 'À copier';
                }

                setTimeout(() => { bouton.innerHTML = original; }, 2000);
            });
        </script>
    @endpush
@endsection
