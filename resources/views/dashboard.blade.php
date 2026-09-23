@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Aperçu</span>
            <h1>Bonjour, {{ $user->name }}</h1>
            <p>Voici l'activité de vos documents et de vos crédits.</p>
        </div>
        <a href="{{ route('documents.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:17px;height:17px"></i> Nouveau document
        </a>
    </div>

    {{-- Statistiques --}}
    {{--
        Les classes `stat-icon` / `value` / `label` employées ici auparavant
        n'existent pas dans le design system : le CSS attend
        `.stat-item .stat-label` et `.stat-item .stat-value`. Les quatre cartes
        s'affichaient donc SANS mise en forme (ni libellé en petites capitales,
        ni chiffre mis en valeur, ni icône alignée) — sur l'écran d'accueil,
        celui que tout utilisateur voit en premier. Le nom fautif ne produisait
        aucune erreur : la classe était simplement ignorée.
    --}}
    <div class="stat-grid">
        <div class="stat-item">
            <div class="stat-label">Documents traités</div>
            <div class="stat-value">{{ $documentsCount }}</div>
            <div class="stat-hint">Tous formats (.docx, .doc, .txt)</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Crédits restants</div>
            <div class="stat-value">{{ number_format($user->credits_balance, 0, ',', ' ') }}</div>
            <div class="stat-hint">1 crédit = 1 FCFA</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Conversations IA</div>
            <div class="stat-value">{{ $chatCount }}</div>
            <div class="stat-hint">Assistant documentaire</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Plan actuel</div>
            <div class="stat-value">{{ ucfirst($planSlug) }}</div>
            <div class="stat-hint">
                <a href="{{ route('account.index') }}">Changer d'offre</a>
            </div>
        </div>
    </div>

    {{-- Quotas du plan --}}
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="component-demo-title" style="margin-bottom:.9rem;">
            Quotas du plan {{ ucfirst($planSlug) }} (réinitialisés le 1er du mois)
        </div>

        @php
            $det = $quotaStatus['deterministic'] ?? null;
            $ai = $quotaStatus['ai'] ?? null;
            $detPct = ($det && $det['quota'] > 0) ? min(100, round($det['used'] / $det['quota'] * 100)) : 0;
            $aiPct = ($ai && $ai['quota'] > 0) ? min(100, round($ai['used'] / $ai['quota'] * 100)) : 0;
        @endphp

        @if ($det)
            <div class="quota-row">
                <span class="quota-label">Documents analysés</span>
                <span class="quota-value">{{ $det['used'] }} / {{ $det['quota'] === null ? '∞' : $det['quota'] }}</span>
            </div>
            <div class="progress" style="margin-bottom:.9rem;">
                <div class="progress-bar" style="width:{{ $detPct }}%;"></div>
            </div>
        @endif

        @if ($ai)
            <div class="quota-row">
                <span class="quota-label">Crédits IA utilisés</span>
                <span class="quota-value">{{ $ai['used'] }} / {{ $ai['quota'] === null ? '∞' : $ai['quota'] }}</span>
            </div>
            <div class="progress">
                <div class="progress-bar" style="width:{{ $aiPct }}%;"></div>
            </div>
        @endif
    </div>

    {{-- Documents récents --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h2 style="font-size:1.05rem;font-weight:700;">Documents récents</h2>
        <a href="{{ route('documents.index') }}" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>

    @forelse ($documents as $doc)
        <div class="card" style="margin-bottom:.9rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                <div style="display:flex;align-items:center;gap:.85rem;min-width:0;">
                    <div class="file-icon" style="width:38px;height:38px;border-radius:var(--radius-sm);background:var(--color-primary-tint);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i data-lucide="file-text" style="width:18px;height:18px"></i>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $doc->filename }}</div>
                        <div style="font-size:.8rem;color:var(--color-text-muted);" class="mono">
                            {{ $doc->created_at->diffForHumans() }} · {{ $doc->metadata['page_count'] ?? '—' }} pages
                        </div>
                    </div>
                </div>
                @if ($doc->status === 'ready')
                    <span class="proof-stamp">✓ Terminé</span>
                @elseif (in_array($doc->status, ['pending', 'detected', 'validated', 'generated']))
                    <span class="proof-stamp pending">⏳ En cours</span>
                @else
                    <span class="proof-stamp failed">✕ Échec</span>
                @endif
            </div>
            {{--
                Pas de barre de progression ici, et c'est délibéré.
                La version précédente affichait `width: 58 %` EN DUR pour tout
                document en cours : un chiffre sans rapport avec l'avancement
                réel, qui ne bougeait jamais, et qui donnait à l'utilisateur
                l'illusion d'un suivi. Le traitement est asynchrone (file
                d'attente) et son avancement n'est pas mesurable par étapes —
                un document est en attente, en traitement, ou terminé.
                Afficher un pourcentage exigerait de l'inventer.
            --}}
            @if ($doc->status === 'ready')
                <div class="progress" style="margin-top:.75rem">
                    <div class="progress-bar" style="width:100%"></div>
                </div>
            @elseif ($doc->status === 'failed')
                <div class="progress" style="margin-top:.75rem">
                    <div class="progress-bar" style="width:100%;background:var(--color-correction)"></div>
                </div>
            @endif
        </div>
    @empty
        <div class="card empty-state">
            <div class="icon-wrap"><i data-lucide="file-text" style="width:26px;height:26px"></i></div>
            <h3>Aucun document pour le moment</h3>
            <p>Téléversez votre premier fichier pour le mettre en forme automatiquement selon un gabarit.</p>
            <a href="{{ route('documents.create') }}" class="btn btn-primary">Téléverser un document</a>
        </div>
    @endforelse
@endsection
