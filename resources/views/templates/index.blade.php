@extends('layouts.app')

@section('title', 'Modèles')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Bibliothèque</span>
            <h1>Modèles</h1>
            <p>Gabarits de mise en forme FORMADOC : polices, styles de titres, mise en page.</p>
        </div>
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
@endsection
