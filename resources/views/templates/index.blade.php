@extends('layouts.app')

@section('title', 'Modèles')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Bibliothèque</span>
            <h1>Modèles</h1>
            <p>Modèles publics FORMADOC et modèles personnels.</p>
        </div>
        <a href="{{ route('cover-templates.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:17px;height:17px"></i> Créer un modèle
        </a>
    </div>

    <div class="tabs">
        <button class="tab active">Tous</button>
        <button class="tab">Rapport</button>
        <button class="tab">Mémoire</button>
        <button class="tab">CV</button>
        <button class="tab">Entreprise</button>
        <button class="tab">Mes modèles</button>
        <button class="tab">Communauté</button>
    </div>

    {{-- Gabarits de mise en forme --}}
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:.9rem;">Gabarits de mise en forme</h2>
    <div class="template-grid" style="margin-bottom:1.75rem;">
        @forelse ($formatTemplates as $tpl)
            <div class="template-card" tabindex="0" role="button"
                 aria-label="Modèle {{ $tpl->name }}"
                 onclick="window.location='{{ route('documents.create') }}'">
                <div class="template-thumb">
                    @php
                        $font = $tpl->params['font'] ?? $tpl->params['police'] ?? 'Calibri';
                        $size = $tpl->params['size'] ?? $tpl->params['taille'] ?? 11;
                        $category = $tpl->params['category'] ?? 'Gabarit';
                    @endphp
                    <div class="tl title"></div>
                    <div class="tl w80"></div>
                    <div class="tl w60"></div>
                    <div class="tl w80"></div>
                </div>
                <div class="template-body">
                    <div class="t-name">{{ $tpl->name }}</div>
                    <div class="t-cat">{{ $category }} · {{ $font }} · {{ $size }}pt</div>
                </div>
            </div>
        @empty
            <div class="card empty-state" style="grid-column:1/-1;">
                <div class="icon-wrap"><i data-lucide="layout-grid" style="width:26px;height:26px"></i></div>
                <h3>Aucun gabarit de mise en forme</h3>
                <p>Les gabarits publics apparaîtront ici.</p>
            </div>
        @endforelse
    </div>

    {{-- Pages de garde --}}
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:.9rem;">Pages de garde</h2>
    <div class="template-grid">
        @forelse ($coverTemplates as $ct)
            <div class="template-card" tabindex="0" role="button"
                 aria-label="Page de garde {{ $ct->name }}"
                 onclick="window.location='{{ route('cover-templates.edit', $ct) }}'">
                <div class="template-thumb">
                    <div class="tl title" style="width:55%"></div>
                    <div class="tl w40" style="background:var(--color-primary);height:3px;margin-top:.6rem;"></div>
                    <div class="tl w80" style="margin-top:.4rem;"></div>
                    <div class="tl w60"></div>
                </div>
                <div class="template-body">
                    <div class="t-name">{{ $ct->name }}</div>
                    <div class="t-cat">{{ $ct->is_public ? 'Public' : 'Personnel' }} · Page de garde</div>
                </div>
            </div>
        @empty
            <div class="card empty-state" style="grid-column:1/-1;">
                <div class="icon-wrap"><i data-lucide="book-open" style="width:26px;height:26px"></i></div>
                <h3>Aucune page de garde</h3>
                <p>Créez votre première page de garde avec le builder visuel.</p>
                <a href="{{ route('cover-templates.create') }}" class="btn btn-primary">Créer une page de garde</a>
            </div>
        @endforelse

        {{-- Carte « Nouveau modèle » (dashed, fidèle au template) --}}
        <a href="{{ route('cover-templates.create') }}" class="template-card"
           style="display:flex;align-items:center;justify-content:center;min-height:170px;border-style:dashed;text-decoration:none;">
            <div style="text-align:center;color:var(--color-text-muted);">
                <i data-lucide="plus" style="width:24px;height:24px;margin:0 auto .4rem;display:block"></i>
                <div style="font-size:.83rem;font-weight:600;">Nouveau modèle</div>
            </div>
        </a>
    </div>
@endsection
