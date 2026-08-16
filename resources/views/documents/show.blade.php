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
                        <span class="badge text-bg-success fs-6">
                            {{ $document->status === 'validated' ? '✔ Validé' : '✔ Analysé' }}
                        </span>
                    </div>

                    {{-- Phase 2 : génération du DOCX reconstruit --}}
                    @if ($document->structure)
                        <div class="d-flex gap-2 mt-3 pt-3 border-top">
                            <form method="POST" action="{{ route('documents.generate', $document) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary">
                                    ⬇ Générer le DOCX reconstruit
                                </button>
                            </form>
                            @if ($errors->any())
                                <div class="alert alert-danger py-2 px-3 mb-0">
                                    {{ $errors->first() }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Phase 4 : validation des ambiguïtés --}}
            @if ($document->structure)
                @php
                    $ambiguities = $structure->ambiguities ?? [];
                @endphp
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Validation des ambiguïtés</h2>
                    </div>
                    <div class="card-body">
                        @if ($document->status === 'validated')
                            <div class="alert alert-success mb-0">
                                ✔ Structure validée. Les corrections ont été appliquées au plan.
                            </div>
                        @elseif (count($ambiguities) > 0)
                            <p class="text-muted">
                                Certaines numérotations ne correspondent pas au niveau détecté.
                                Vérifiez et corrigez si nécessaire, puis validez la structure.
                            </p>
                            <form method="POST" action="{{ route('documents.validate', $document) }}">
                                @csrf
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-3">
                                        <thead>
                                            <tr>
                                                <th>Titre</th>
                                                <th>Niveau détecté</th>
                                                <th>Motif</th>
                                                <th style="min-width: 200px;">Correction</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($ambiguities as $ambiguity)
                                                <tr>
                                                    <td class="fw-semibold">{{ $ambiguity['texte'] }}</td>
                                                    <td>Niveau {{ $ambiguity['niveau_detecte'] }}</td>
                                                    <td class="text-muted">
                                                        {{ $ambiguity['raison'] }}
                                                        <span class="badge text-bg-warning text-dark ms-1">
                                                            Suggéré : niveau {{ $ambiguity['niveau_suggere'] }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <select name="corrections[{{ $ambiguity['id'] }}]"
                                                                class="form-select form-select-sm" aria-label="Correction pour {{ $ambiguity['texte'] }}">
                                                            <option value="1" @selected(($ambiguity['niveau_detecte'] ?? null) === 1)>Titre (niveau 1)</option>
                                                            <option value="2" @selected(($ambiguity['niveau_detecte'] ?? null) === 2)>Sous-titre (niveau 2)</option>
                                                            <option value="3">Sous-titre (niveau 3)</option>
                                                            <option value="remove">Retirer du plan</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" class="btn btn-success">✔ Valider la structure</button>
                            </form>
                        @else
                            <p class="text-muted mb-3">Aucune ambiguïté détectée. La structure semble cohérente.</p>
                            <form method="POST" action="{{ route('documents.validate', $document) }}">
                                @csrf
                                <button type="submit" class="btn btn-success">✔ Valider la structure</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Phase 3 : génération avec couverture personnalisée --}}
            @if ($document->structure)
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Couverture personnalisée</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Fournissez une couverture d'exemple (<code>.docx</code>) : le système
                            détecte les zones (nom, titre, encadrant, date) et les remplace en
                            conservant la structure et les styles.
                        </p>
                        <form method="POST" action="{{ route('documents.generate-cover', $document) }}" enctype="multipart/form-data">
                            @csrf
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="cover" class="form-label">Couverture d'exemple (.docx)</label>
                                    <input type="file" class="form-control @error('cover') is-invalid @enderror"
                                           id="cover" name="cover" accept=".docx" required>
                                    @error('cover')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="nom" class="form-label">Nom</label>
                                    <input type="text" class="form-control" id="nom" name="nom"
                                           placeholder="Ex : JEAN DUPONT">
                                </div>
                                <div class="col-md-6">
                                    <label for="titre" class="form-label">Titre</label>
                                    <input type="text" class="form-control" id="titre" name="titre"
                                           placeholder="Ex : CONCEPTION D'UNE APPLICATION WEB">
                                </div>
                                <div class="col-md-6">
                                    <label for="encadrant" class="form-label">Encadrant</label>
                                    <input type="text" class="form-control" id="encadrant" name="encadrant"
                                           placeholder="Ex : Dr. MARTIN">
                                </div>
                                <div class="col-md-6">
                                    <label for="date" class="form-label">Date / Année académique</label>
                                    <input type="text" class="form-control" id="date" name="date"
                                           placeholder="Ex : 2025-2026">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success">🎓 Générer le DOCX avec couverture</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

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
