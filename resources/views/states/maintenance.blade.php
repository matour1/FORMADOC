@extends('layouts.app')

@section('title', 'Maintenance')

@section('content')
    <div class="error-page">
        <div class="spinner" style="margin:0 auto 1.5rem;" role="status" aria-label="Maintenance en cours"></div>
        <h2 style="font-size:1.2rem;font-weight:700;">Maintenance en cours</h2>
        <p class="message">Nous améliorons FORMADOC. De retour dans quelques instants.</p>
        <p class="error-detail">Maintenance planifiée · Retour estimé : 14h30 UTC · REF-MAINT-2026-08</p>
    </div>
@endsection
