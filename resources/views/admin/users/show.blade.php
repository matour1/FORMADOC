@extends('layouts.admin')

@section('title', $utilisateur->name)

@section('content')
    <div class="page-head">
        <span class="eyebrow">
            <a href="{{ route('admin.users.index') }}" style="color:inherit">Comptes</a> ·
            <span class="mono">{{ $utilisateur->id }}</span>
        </span>
        <h1>{{ $utilisateur->name }}</h1>
        <p>{{ $utilisateur->email }}</p>
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

    @if ($utilisateur->is_suspended)
        <div class="banner banner-danger">
            <i data-lucide="ban"></i>
            <div>
                <strong>Compte suspendu</strong>
                <div style="font-size:.85rem;margin-top:.25rem">
                    La connexion est refusée.
                    @if ($utilisateur->suspension_reason)
                        Motif enregistré : {{ $utilisateur->suspension_reason }}
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="split-grid">
        {{-- Colonne principale --}}
        <div style="display:flex;flex-direction:column;gap:1.5rem">
            {{-- Ajustement des crédits --}}
            <section class="card">
                <h2 class="card-title" style="margin-bottom:.3rem">Solde de crédits</h2>
                <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                    Un ajustement passe par le même service que les achats : il verrouille le solde,
                    écrit une ligne d'historique et refuse un débit qui rendrait le solde négatif.
                </p>

                <div class="stat-item" style="margin-bottom:1.25rem">
                    <span class="stat-label">Solde actuel</span>
                    <span class="stat-value tabular">{{ number_format($utilisateur->credits_balance, 0, ',', ' ') }}</span>
                    <span class="stat-hint">1 crédit = 1 FCFA</span>
                </div>

                <form method="POST" action="{{ route('admin.users.credits', $utilisateur) }}">
                    @csrf

                    <div class="form-group">
                        <label for="amount">Montant</label>
                        <input type="number" id="amount" name="amount" step="1"
                               class="form-control" placeholder="500 ou -200" required>
                        {{-- Signe expliqué : c'est la seule chose qui distingue un
                             crédit d'un débit, et une valeur négative saisie par
                             erreur donnerait l'inverse de l'intention. --}}
                        <p class="form-hint">
                            Positif pour créditer, négatif pour débiter. Exemple : <code>500</code> ajoute
                            500 crédits, <code>-200</code> en retire 200.
                        </p>
                        @error('amount')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="reason">Motif (obligatoire)</label>
                        <input type="text" id="reason" name="reason" maxlength="255"
                               class="form-control" placeholder="Geste commercial, correction d'incident…" required>
                        <p class="form-hint">
                            Conservé avec l'auteur de l'action. Sans motif, un ajustement est
                            indistinguable d'une erreur six mois plus tard.
                        </p>
                        @error('reason')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="coins" style="width:15px;height:15px"></i> Appliquer l'ajustement
                    </button>
                </form>
            </section>

            {{-- Historique des crédits --}}
            <section class="card">
                <h2 class="card-title" style="margin-bottom:1rem">Historique des crédits</h2>

                @if ($transactions->isEmpty())
                    <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                        Aucun mouvement de crédits sur ce compte.
                    </p>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Montant</th>
                                    <th>Solde après</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $transaction)
                                    @php
                                        $types = [
                                            'purchase' => 'Achat',
                                            'usage' => 'Utilisation',
                                            'refund' => 'Remboursement',
                                            'bonus' => 'Bonus',
                                            'adjustment' => 'Ajustement',
                                        ];
                                    @endphp
                                    <tr>
                                        <td data-label="Date" style="font-size:.78rem">
                                            {{ $transaction->created_at?->format('d/m/Y H:i') }}
                                        </td>
                                        <td data-label="Type" style="font-size:.78rem">
                                            {{ $types[$transaction->type] ?? $transaction->type }}
                                        </td>
                                        <td data-label="Montant" class="num" style="font-size:.78rem">
                                            <span style="color:{{ $transaction->amount >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }}">
                                                {{ $transaction->amount > 0 ? '+' : '' }}{{ number_format($transaction->amount, 0, ',', ' ') }}
                                            </span>
                                        </td>
                                        <td data-label="Solde après" class="num" style="font-size:.78rem">
                                            {{ number_format($transaction->balance_after, 0, ',', ' ') }}
                                        </td>
                                        <td data-label="Description" style="font-size:.78rem">
                                            {{ $transaction->description ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Paiements --}}
            <section class="card">
                <h2 class="card-title" style="margin-bottom:1rem">Paiements encaissés</h2>

                @if ($paiements->isEmpty())
                    <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                        Aucun paiement pour ce compte.
                    </p>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Montant</th>
                                    <th>Statut</th>
                                    <th>Objet</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($paiements as $paiement)
                                    <tr>
                                        <td data-label="Référence" class="mono" style="font-size:.72rem">
                                            {{ $paiement->payment_id ?: $paiement->external_id }}
                                        </td>
                                        <td data-label="Montant" class="num" style="font-size:.78rem">
                                            {{ number_format($paiement->amount_fcfa, 0, ',', ' ') }} {{ $paiement->currency }}
                                        </td>
                                        <td data-label="Statut" style="font-size:.78rem">
                                            @php
                                                $statuts = [
                                                    'completed' => ['validé', 'badge-success'],
                                                    'pending' => ['en attente', 'badge-warning'],
                                                    'processing' => ['en cours', 'badge-warning'],
                                                    'failed' => ['échec', 'badge-danger'],
                                                    'cancelled' => ['annulé', 'badge'],
                                                ];
                                                [$libelle, $classe] = $statuts[$paiement->status] ?? [$paiement->status, 'badge'];
                                            @endphp
                                            <span class="badge {{ $classe }}">{{ $libelle }}</span>
                                        </td>
                                        <td data-label="Objet" style="font-size:.78rem">{{ $paiement->purpose }}</td>
                                        <td data-label="Date" style="font-size:.78rem">
                                            {{ $paiement->paid_at ? \Illuminate\Support\Carbon::parse($paiement->paid_at)->format('d/m/Y H:i') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Documents --}}
            <section class="card">
                <h2 class="card-title" style="margin-bottom:1rem">Documents</h2>

                @if ($documents->isEmpty())
                    <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                        Aucun document déposé par ce compte.
                    </p>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr><th>Fichier</th><th>Statut</th><th>Déposé le</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $document)
                                    <tr>
                                        <td data-label="Fichier" style="font-size:.8rem">{{ $document->filename }}</td>
                                        <td data-label="Statut" style="font-size:.78rem">{{ $document->status }}</td>
                                        <td data-label="Déposé le" style="font-size:.78rem">
                                            {{ $document->created_at ? \Illuminate\Support\Carbon::parse($document->created_at)->format('d/m/Y H:i') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        {{-- Colonne latérale : actions --}}
        <div style="display:flex;flex-direction:column;gap:1.5rem">
            <section class="card">
                <h2 class="card-title" style="margin-bottom:.3rem">État du compte</h2>
                <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                    Inscrit le {{ $utilisateur->created_at?->format('d/m/Y') }}.
                    @if ($utilisateur->email_verified_at)
                        Adresse confirmée.
                    @else
                        Adresse <strong>non confirmée</strong>.
                    @endif
                </p>

                <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.85rem;margin-bottom:1.25rem">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Plan</span>
                        <span>{{ $utilisateur->currentPlanSlug() }}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Abonnement</span>
                        <span>
                            @if ($abonnement)
                                {{ $abonnement->status }} jusqu'au {{ $abonnement->ends_at?->format('d/m/Y') }}
                            @else
                                aucun
                            @endif
                        </span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Factures</span>
                        <span>{{ $factures->count() }}</span>
                    </div>
                </div>

                {{-- Administrateur --}}
                <form method="POST" action="{{ route('admin.users.admin', $utilisateur) }}" style="margin-bottom:.75rem">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-block"
                            {{ $utilisateur->is(auth()->user()) ? 'disabled' : '' }}>
                        <i data-lucide="shield-check" style="width:15px;height:15px"></i>
                        {{ $utilisateur->is_admin ? 'Retirer les droits admin' : 'Nommer administrateur' }}
                    </button>
                    @if ($utilisateur->is(auth()->user()))
                        <p class="form-hint" style="margin-top:.4rem">
                            Vous ne pouvez pas modifier vos propres droits : vous perdriez l'accès à cet
                            espace sans pouvoir le rétablir.
                        </p>
                    @endif
                </form>

                {{-- Suspension --}}
                <form method="POST" action="{{ route('admin.users.suspension', $utilisateur) }}">
                    @csrf
                    <button type="submit"
                            class="btn {{ $utilisateur->is_suspended ? 'btn-primary' : 'btn-secondary' }} btn-block"
                            {{ $utilisateur->is(auth()->user()) ? 'disabled' : '' }}>
                        <i data-lucide="{{ $utilisateur->is_suspended ? 'user-check' : 'ban' }}" style="width:15px;height:15px"></i>
                        {{ $utilisateur->is_suspended ? 'Rétablir le compte' : 'Suspendre le compte' }}
                    </button>
                </form>

                <p class="form-hint" style="margin-top:.85rem">
                    La suspension est réversible et ne supprime aucune donnée. Aucune suppression de
                    compte n'est proposée depuis cet écran : elle emporterait des factures et un
                    historique de paiement dont la conservation engage l'éditeur.
                </p>
            </section>
        </div>
    </div>
@endsection
