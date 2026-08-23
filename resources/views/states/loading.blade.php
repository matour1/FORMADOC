@extends('layouts.app')

@section('title', 'États de chargement')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Démonstration</span>
            <h1>États de chargement</h1>
            <p>Squelettes utilisés pendant les appels réseau ou l'analyse d'un document.</p>
        </div>
    </div>
    <div class="card" style="margin-bottom:1rem;display:flex;align-items:center;gap:1rem;">
        <div class="spinner" role="status" aria-label="Chargement en cours"></div>
        <div>
            <div style="font-weight:600;">Analyse du document en cours…</div>
            <div style="font-size:.83rem;color:var(--color-text-muted);">Cela peut prendre jusqu'à 60 secondes pour 50 pages.</div>
        </div>
    </div>
    <div class="card">
        <div class="skeleton" style="height:22px;width:40%;margin-bottom:.9rem;"></div>
        <div class="skeleton" style="height:14px;width:90%;margin-bottom:.5rem;"></div>
        <div class="skeleton" style="height:14px;width:75%;margin-bottom:.5rem;"></div>
        <div class="skeleton" style="height:14px;width:85%;margin-bottom:1.1rem;"></div>
        <div style="display:flex;gap:.7rem;">
            <div class="skeleton" style="height:60px;flex:1;"></div>
            <div class="skeleton" style="height:60px;flex:1;"></div>
            <div class="skeleton" style="height:60px;flex:1;"></div>
        </div>
    </div>
@endsection
