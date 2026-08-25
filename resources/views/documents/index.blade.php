@extends('layouts.app')

@section('title', 'Mes documents')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Bibliothèque</span>
            <h1>Mes documents</h1>
            <p>Retrouvez, prévisualisez et téléchargez tous vos documents traités.</p>
        </div>
        <a href="{{ route('documents.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:17px;height:17px"></i> Nouveau document
        </a>
    </div>

    {{-- Barre d'outils : filtres + recherche --}}
    <div class="doc-toolbar">
        <div class="segmented" id="docFilters">
            @php
                $filters = [
                    'all' => 'Tous',
                    'processing' => 'En cours',
                    'done' => 'Terminés',
                    'failed' => 'Échecs',
                ];
            @endphp
            @foreach ($filters as $key => $label)
                <a href="{{ route('documents.index', array_filter(['filter' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
                   class="{{ $filter === $key ? 'active' : '' }}" data-doc-filter="{{ $key }}">
                    {{ $label }}
                    @isset($statusCounts[$key])
                        <span class="seg-count">{{ $statusCounts[$key] }}</span>
                    @endisset
                </a>
            @endforeach
        </div>
        <form class="search-bar" method="GET" action="{{ route('documents.index') }}" role="search">
            <i data-lucide="search" style="width:16px;height:16px"></i>
            <label for="docSearch" class="sr-only">Rechercher un document</label>
            <input id="docSearch" type="text" name="q" value="{{ $search }}" placeholder="Filtrer par nom…" />
            @if ($filter !== 'all')
                <input type="hidden" name="filter" value="{{ $filter }}">
            @endif
        </form>
    </div>

    {{-- Liste des documents --}}
    @forelse ($documents as $doc)
        <div class="doc-row" data-doc-status="{{ $doc->status }}">
            <div class="file-icon">
                <i data-lucide="file-text" style="width:18px;height:18px"></i>
            </div>
            <div class="doc-meta">
                <div class="doc-name">{{ $doc->filename }}</div>
                <div class="doc-sub">
                    {{ $doc->metadata['template_name'] ?? 'Gabarit standard' }} ·
                    {{ $doc->metadata['page_count'] ?? '—' }} pages ·
                    {{ $doc->created_at->format('d/m/Y') }}
                </div>
            </div>

            @if ($doc->status === 'ready')
                <span class="proof-stamp">✓ Terminé</span>
            @elseif ($doc->status === 'processing')
                <span class="proof-stamp pending">⏳ IA en cours</span>
            @elseif (in_array($doc->status, ['pending', 'detected', 'validated', 'generated']))
                <span class="proof-stamp pending">⏳ En cours</span>
            @else
                <span class="proof-stamp failed">✕ Échec</span>
            @endif

            <div class="doc-actions">
                @if ($doc->status === 'ready' || $doc->status === 'generated')
                    <a href="{{ route('documents.preview', $doc) }}" class="btn btn-secondary btn-sm">Aperçu</a>
                    <a href="{{ route('documents.export', $doc) }}" class="icon-btn" aria-label="Télécharger" title="Télécharger le DOCX">
                        <i data-lucide="download" style="width:16px;height:16px"></i>
                    </a>
                @elseif ($doc->status === 'processing')
                    <span class="doc-sub" style="white-space:nowrap">Traitement IA en cours…</span>
                @elseif (in_array($doc->status, ['pending', 'detected', 'validated']))
                    <a href="{{ route('documents.show', $doc) }}" class="btn btn-secondary btn-sm">Suivre</a>
                @else
                    <a href="{{ route('documents.create') }}" class="btn btn-secondary btn-sm">Réessayer</a>
                @endif
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

    {{-- Pagination --}}
    @if ($documents->hasPages())
        <div class="pagination" style="margin-top:1.5rem;justify-content:center;">
            {{ $documents->links() }}
        </div>
    @endif
@endsection
