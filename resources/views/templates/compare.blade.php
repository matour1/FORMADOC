@extends('layouts.app')

@section('title', 'Comparer des gabarits')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Personnalisation avancée</span>
            <h1>Comparer des gabarits</h1>
            <p>Placez deux gabarits côte à côte pour choisir celui qui convient le mieux à votre document.</p>
        </div>
        @if ($premium)
            <span class="badge badge-info">Premium et +</span>
        @else
            <span class="badge badge-warning">Réservé Premium et +</span>
        @endif
    </div>

    @if ($premium)
        <div class="compare-grid">
            {{-- Premier gabarit --}}
            <div class="compare-col">
                <div class="compare-col-head">
                    <select aria-label="Choisir le premier gabarit" class="form-control">
                        @foreach ($templates as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                        @endforeach
                    </select>
                    <span class="badge badge-success">Sélectionné</span>
                </div>
                <div class="compare-preview">
                    <div class="cl title"></div>
                    <div class="cl w90"></div><div class="cl w70"></div><div class="cl w80"></div>
                    <div style="height:.6rem;"></div>
                    <div class="cl w50"></div>
                    <div class="cl w90"></div><div class="cl w80"></div>
                </div>
                <div class="compare-specs">
                    @php $first = $templates->first(); @endphp
                    @if ($first)
                        <span class="badge mono">{{ $first->params['font'] ?? 'Calibri' }}</span>
                        <span class="badge mono">Titre 1 · {{ $first->params['title_size'] ?? 16 }}pt</span>
                        <span class="badge mono">Interligne {{ $first->params['line_spacing'] ?? 1.5 }}</span>
                        <span class="badge mono">Marges {{ $first->params['margin_mm'] ?? 25 }}mm</span>
                    @endif
                </div>
            </div>

            {{-- Deuxième gabarit --}}
            <div class="compare-col">
                <div class="compare-col-head">
                    <select aria-label="Choisir le deuxième gabarit" class="form-control">
                        @foreach ($templates->slice(1) as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                        @endforeach
                        @if ($templates->count() < 2)
                            <option>Rapport d'entreprise</option>
                        @endif
                    </select>
                    <a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm">Utiliser celui-ci</a>
                </div>
                <div class="compare-preview">
                    <div class="cl title"></div>
                    <div class="cl w80"></div><div class="cl w90"></div>
                    <div style="height:.6rem;"></div>
                    <div class="cl w50"></div>
                    <div class="cl w70"></div><div class="cl w90"></div><div class="cl w80"></div>
                </div>
                <div class="compare-specs">
                    @php $second = $templates->get(1); @endphp
                    @if ($second)
                        <span class="badge mono">{{ $second->params['font'] ?? 'Calibri' }}</span>
                        <span class="badge compare-diff mono">Titre 1 · {{ $second->params['title_size'] ?? 14 }}pt</span>
                        <span class="badge compare-diff mono">Interligne {{ $second->params['line_spacing'] ?? 1.15 }}</span>
                        <span class="badge mono">Marges {{ $second->params['margin_mm'] ?? 25 }}mm</span>
                    @else
                        <span class="badge mono">Calibri</span>
                        <span class="badge compare-diff mono">Titre 1 · 14pt</span>
                        <span class="badge compare-diff mono">Interligne 1.15</span>
                        <span class="badge mono">Marges 2.5cm</span>
                    @endif
                </div>
            </div>
        </div>
        <p class="form-hint" style="margin-top:1rem;">Les valeurs en rouge diffèrent du premier gabarit sélectionné.</p>
    @else
        <div class="card empty-state">
            <div class="icon-wrap"><i data-lucide="lock" style="width:26px;height:26px"></i></div>
            <h3>Fonctionnalité Premium</h3>
            <p>La comparaison de gabarits est réservée aux plans Premium et supérieurs. Passez à Premium pour comparer les gabarits côte à côte.</p>
            <a href="{{ route('account.index') }}" class="btn btn-primary">Voir les plans</a>
        </div>
    @endif
@endsection
