@extends('layouts.app')

@section('title', 'États vides')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Démonstration</span>
            <h1>États vides</h1>
            <p>Écrans affichés quand il n'y a encore rien à montrer.</p>
        </div>
    </div>
    <div class="card empty-state">
        <div class="icon-wrap"><i data-lucide="file" style="width:26px;height:26px"></i></div>
        <h3>Aucun document pour le moment</h3>
        <p>Téléversez votre premier fichier pour le mettre en forme automatiquement selon un gabarit.</p>
        <a href="{{ route('documents.create') }}" class="btn btn-primary">Téléverser un document</a>
    </div>
@endsection
