@extends('layouts.admin')

@section('title', 'Liens de paiement')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Encaissement</span>
        <h1>Liens de paiement</h1>
        <p>
            Adresses à transmettre à un client pour qu'il règle un montant précis, avec ou
            sans compte FORMADOC. KPay n'exposant pas de « lien de paiement », ce sont des
            liens FORMADOC qui ouvrent la passerelle au moment du règlement.
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

    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">En attente</span>
            <span class="stat-value tabular">{{ number_format($totalEnAttente, 0, ',', ' ') }}</span>
            <span class="stat-hint">encore honorables</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Réglés</span>
            <span class="stat-value tabular">{{ number_format($totalPayes, 0, ',', ' ') }}</span>
            <span class="stat-hint">depuis le début</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Montant encaissé</span>
            <span class="stat-value tabular">{{ number_format($montantPaye, 0, ',', ' ') }}</span>
            <span class="stat-hint">FCFA par liens de paiement</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Expirés</span>
            <span class="stat-value tabular">{{ number_format($totalExpires, 0, ',', ' ') }}</span>
            <span class="stat-hint">à relancer ou recréer</span>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem">
        <div class="tabs" style="margin:0;border:none">
            @foreach (['tous' => 'Tous', 'attente' => 'En attente', 'payes' => 'Réglés', 'expires' => 'Expirés', 'annules' => 'Annulés'] as $slug => $libelle)
                <a class="tab {{ $filtre === $slug ? 'active' : '' }}"
                   href="{{ route('admin.payment-links.index', ['etat' => $slug]) }}">{{ $libelle }}</a>
            @endforeach
        </div>

        <a href="{{ route('admin.payment-links.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:15px;height:15px"></i> Créer un lien
        </a>
    </div>

    @if ($liens->isEmpty())
        <div class="card">
            <p style="margin:0;color:var(--color-text-secondary);font-size:.9rem">
                Aucun lien de paiement dans cette catégorie.
            </p>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Objet</th>
                        <th>Montant</th>
                        <th>Mode</th>
                        <th>Destinataire</th>
                        <th>État</th>
                        <th>Expire le</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($liens as $lien)
                        <tr>
                            <td data-label="Objet" style="font-size:.82rem">
                                {{ $lien->label ?: 'Lien #'.$lien->id }}
                                @if ($lien->description)
                                    <div style="font-size:.72rem;color:var(--color-text-muted)">
                                        {{ \Illuminate\Support\Str::limit($lien->description, 60) }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Montant" class="num" style="font-size:.82rem">
                                {{ number_format($lien->amount_fcfa, 0, ',', ' ') }}
                                <span style="font-size:.72rem;color:var(--color-text-muted)">{{ $lien->currency }}</span>
                            </td>
                            <td data-label="Mode" style="font-size:.78rem">
                                {{ $lien->mode === 'online' ? 'En ligne' : 'Hors ligne' }}
                            </td>
                            <td data-label="Destinataire" style="font-size:.78rem">
                                @if ($lien->user)
                                    <div>{{ $lien->user->name }}</div>
                                    <div class="mono" style="font-size:.7rem;color:var(--color-text-muted)">
                                        {{ $lien->user->email }}
                                    </div>
                                @elseif ($lien->customer_email)
                                    <span class="mono" style="font-size:.72rem">{{ $lien->customer_email }}</span>
                                @else
                                    <span style="color:var(--color-text-muted)">Client externe</span>
                                @endif
                            </td>
                            <td data-label="État">
                                @if ($lien->status === 'paid')
                                    <span class="badge badge-success">Réglé</span>
                                @elseif ($lien->status === 'cancelled')
                                    <span class="badge">Annulé</span>
                                @elseif ($lien->estExpire())
                                    <span class="badge badge-danger">Expiré</span>
                                @else
                                    <span class="badge badge-warning">En attente</span>
                                @endif
                            </td>
                            <td data-label="Expire le" style="font-size:.78rem">
                                {{ $lien->expires_at?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td>
                                <a href="{{ route('admin.payment-links.show', $lien) }}" style="font-size:.8rem">
                                    Ouvrir
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination">{{ $liens->links() }}</div>
    @endif
@endsection
