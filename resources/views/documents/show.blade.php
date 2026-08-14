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
                    $titlesMarkdown = $data['titles'] ?? '';
                    $legends = $data['legends'] ?? [];

                    // Parse les lignes markdown de titres, en ignorant :
                    // - l'en-tête généré par le LLM ("# Arborescence des titres extraits")
                    // - les sections de synthèse du LLM ("## Récapitulatif", "## Ambiguïtés…")
                    $parsedTitles = [];
                    foreach (preg_split('/\r\n|\r|\n/', (string) $titlesMarkdown) as $line) {
                        $line = trim($line);
                        if ($line === '' || $line === '---') {
                            continue;
                        }
                        // Supporte "# Titre" et "- # Titre" (liste markdown)
                        if (str_starts_with($line, '- ')) {
                            $line = trim(substr($line, 2));
                        }
                        // En-tête de la réponse LLM : on l'ignore (formats variables observés)
                        if (preg_match('/^#{1,6}\s+Arborescence des titres/iu', $line)) {
                            continue;
                        }
                        // Lignes d'introduction avant la liste (phrases sans #)
                        if (!preg_match('/^#{1,6}\s+/', $line)) {
                            continue;
                        }
                        // Sections de synthèse du LLM : tout ce qui suit n'est plus un titre du document
                        if (preg_match('/^#{1,6}\s+(Récapitulatif|Ambiguïtés)/iu', $line)) {
                            break;
                        }
                        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                            $parsedTitles[] = [
                                'level' => strlen($m[1]),
                                'text' => $m[2],
                            ];
                        }
                    }
                    $titleCount = count($parsedTitles);
                @endphp

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Hiérarchie des titres détectée</h2>
                    </div>
                    <div class="card-body">
                        @if ($titleCount > 0)
                            <div class="mb-3">
                                <span class="badge text-bg-primary">{{ $titleCount }} titres détectés</span>
                            </div>
                            {{-- Rendu hiérarchique des titres --}}
                            <div class="bg-light p-3 rounded">
                                @foreach ($parsedTitles as $titre)
                                    @php $niveau = $titre['level']; @endphp
                                    <h{{ $niveau }} class="mb-1">{{ $titre['text'] }}</h{{ $niveau }}>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted mb-0">Aucun titre détecté dans ce document.</p>
                        @endif
                    </div>
                </div>

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
