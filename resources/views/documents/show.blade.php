@extends('layouts.app')

@section('title', 'Résultat de l\'analyse')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                </div>
            @endif

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-1">{{ $document->filename }}</h1>
                            <p class="text-muted mb-0">
                                Taille : {{ number_format(($document->metadata['size'] ?? 0) / 1024, 1) }} Ko
                                — Type : {{ $document->metadata['mime_type'] ?? 'inconnu' }}
                            </p>
                        </div>
                        <span class="badge text-bg-success fs-6">✔ Analysé</span>
                    </div>
                </div>
            </div>

            @if ($structure)
                @php
                    $data = $structure->structure ?? [];
                    $titres = $data['titres'] ?? [];
                    $sousTitres = $data['sous_titres'] ?? [];
                    $enTetes = $data['en_tetes'] ?? [];
                    $piedsDePage = $data['pieds_de_page'] ?? [];
                    $legends = $data['legends'] ?? [];
                    $titreCount = count($titres) + count($sousTitres);
                @endphp

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Hiérarchie des titres détectée</h2>
                    </div>
                    <div class="card-body">
                        @if ($titreCount > 0)
                            <div class="mb-3">
                                <span class="badge text-bg-primary">{{ $titreCount }} titres détectés</span>
                            </div>
                            {{-- Rendu hiérarchique : titres niveau 1, sous-titres niveau 2+ --}}
                            <div class="bg-light p-3 rounded">
                                @foreach ($titres as $titre)
                                    <h3 class="mb-1 fw-bold">{{ $titre['texte'] ?? '' }}</h3>
                                @endforeach
                                @foreach ($sousTitres as $sousTitre)
                                    <h5 class="mb-1 text-secondary">{{ $sousTitre['texte'] ?? '' }}</h5>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted mb-0">Aucun titre détecté dans ce document.</p>
                        @endif
                    </div>
                </div>

                @if (count($enTetes) > 0 || count($piedsDePage) > 0)
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <h2 class="h5 mb-0">En-têtes et pieds de page détectés</h2>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-muted">En-têtes</h6>
                                    <ul class="list-unstyled mb-0">
                                        @forelse ($enTetes as $enTete)
                                            <li>{{ $enTete['texte'] ?? '' }}</li>
                                        @empty
                                            <li class="text-muted">Aucun en-tête.</li>
                                        @endforelse
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Pieds de page</h6>
                                    <ul class="list-unstyled mb-0">
                                        @forelse ($piedsDePage as $pied)
                                            <li>{{ $pied['texte'] ?? '' }}</li>
                                        @empty
                                            <li class="text-muted">Aucun pied de page.</li>
                                        @endforelse
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Légendes détectées (figures, tableaux, annexes…)</h2>
                    </div>
                    <div class="card-body">
                        @if (count($legends) > 0)
                            <div class="mb-3">
                                <span class="badge text-bg-info">{{ count($legends) }} légendes détectées</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Ligne</th>
                                            <th>Type</th>
                                            <th>N°</th>
                                            <th>Libellé</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($legends as $legend)
                                            <tr>
                                                <td class="text-muted">{{ $legend['line'] }}</td>
                                                <td>{{ $legend['type'] }}</td>
                                                <td>{{ $legend['number'] }}</td>
                                                <td>{{ $legend['label'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted mb-0">Aucune légende détectée.</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="alert alert-warning">
                    La structure de ce document n'a pas encore été analysée.
                </div>
            @endif

            <div class="d-flex gap-2">
                <a href="{{ route('documents.create') }}" class="btn btn-outline-primary">Analyser un autre rapport</a>
                <a href="/" class="btn btn-outline-secondary">Accueil</a>
            </div>

        </div>
    </div>
</div>
@endsection
