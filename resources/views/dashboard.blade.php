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
    <div class="stat-grid">
        <div class="stat-item">
            <div class="stat-icon"><i data-lucide="file-text" style="width:18px;height:18px"></i></div>
            <div class="value">{{ $documentsCount }}</div>
            <div class="label">Documents traités</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon"><i data-lucide="coins" style="width:18px;height:18px"></i></div>
            <div class="value mono">{{ number_format($user->credits_balance, 0, ',', ' ') }}</div>
            <div class="label">Crédits restants</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon"><i data-lucide="message-circle" style="width:18px;height:18px"></i></div>
            <div class="value">{{ $chatCount }}</div>
            <div class="label">Conversations IA</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon"><i data-lucide="layout-grid" style="width:18px;height:18px"></i></div>
            <div class="value" style="font-size:1rem">{{ ucfirst($planSlug) }}</div>
            <div class="label">Plan actuel</div>
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
                @if ($doc->status === 'ready')
                    <a href="{{ route('documents.export', $doc) }}" class="icon-btn" aria-label="Télécharger le document" title="Télécharger le DOCX">
                        <i data-lucide="download" style="width:16px;height:16px"></i>
                    </a>
                @endif
            </div>
            <div style="margin-top:.75rem;">
                <div class="progress">
                    <div class="progress-bar" style="width:{{ $doc->status === 'ready' ? 100 : ($doc->status === 'failed' ? 100 : 58) }}%;"></div>
                </div>
            </div>
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
