@extends('layouts.app')

@section('title', 'Aperçu indisponible')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Résultat · repli</span>
            <h1>Aperçu indisponible</h1>
            <p>Le service d'aperçu est temporairement indisponible — votre document reste généré et téléchargeable.</p>
        </div>
    </div>

    <div class="banner banner-warning" role="status">
        <i data-lucide="alert-triangle" style="width:18px;height:18px;flex-shrink:0"></i>
        <div>Le service d'aperçu est temporairement indisponible. Le document DOCX reste généré et téléchargeable normalement.</div>
    </div>

    <div class="card empty-state">
        <div class="icon-wrap"><i data-lucide="eye-off" style="width:26px;height:26px"></i></div>
        <h3>Aperçu visuel temporairement indisponible</h3>
        <p>Vous pouvez tout de même télécharger le document mis en forme, ou réessayer l'aperçu plus tard.</p>
        <div style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;">
            <a href="{{ route('documents.index') }}" class="btn btn-primary">Télécharger DOCX</a>
            <button class="btn btn-secondary" type="button" onclick="window.location.reload()">Réessayer l'aperçu</button>
        </div>
    </div>
@endsection
