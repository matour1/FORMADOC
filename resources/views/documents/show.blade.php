@extends('layouts.app')

@section('title', 'Validation de la structure')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 2, 'document' => $document])

    @php
        $ambiguities = $structure->ambiguities ?? [];
        $data = $structure->structure ?? [];
        $titres = $data['titres'] ?? [];
        $sousTitres = $data['sous_titres'] ?? [];
        $enTetes = $data['en_tetes'] ?? [];
        $piedsDePage = $data['pieds_de_page'] ?? [];
        $legends = $data['legends'] ?? [];
        $tableaux = $data['tableaux'] ?? [];
        $titreCount = count($titres) + count($sousTitres);
        $isValidated = $document->status === 'validated';
    @endphp

    <div class="md:ml-64">
        <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

            {{-- En-tête de page --}}
            <div class="mb-8 md:mb-12">
                <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Étape 2 / 4 — Validation</p>
                <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-2 break-words">
                    {{ $document->filename }}
                </h1>
                <p class="font-caption text-caption text-on-surface-variant">
                    {{ number_format(($document->metadata['size'] ?? 0) / 1024, 1) }} Ko
                    · {{ $document->metadata['mime_type'] ?? 'type inconnu' }}
                </p>
            </div>

            {{-- Alertes --}}
            @if (session('success'))
                <div class="flex items-start gap-3 bg-surface-container-lowest border border-outline-variant rounded-xl p-4 mb-6">
                    <span class="material-symbols-outlined text-primary">check_circle</span>
                    <p class="text-body-md">{{ session('success') }}</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                    <span class="material-symbols-outlined text-error">error</span>
                    <ul class="text-body-md text-on-error-container">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! $structure)
                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 mb-6">
                    <p class="text-body-md text-on-surface-variant">
                        La structure de ce document n'a pas encore été analysée.
                    </p>
                </div>
            @else
                {{-- Bandeau d'état --}}
                <div class="flex flex-wrap items-center justify-between gap-4 bg-surface-container-lowest border border-outline-variant rounded-xl p-5 mb-6">
                    <div class="flex items-center gap-3">
                        @if ($isValidated)
                            <span class="material-symbols-outlined text-primary text-[28px]">verified</span>
                            <p class="text-body-md font-semibold">Structure validée — prête pour le traitement.</p>
                        @else
                            <span class="material-symbols-outlined text-secondary text-[28px]">fact_check</span>
                            <p class="text-body-md">Vérifiez la structure détectée puis lancez le traitement.</p>
                        @endif
                    </div>
                    @if ($isValidated)
                        <a href="{{ route('documents.processing', $document) }}"
                           class="inline-flex items-center gap-2 bg-primary hover:bg-primary-fixed-variant text-on-primary px-5 py-3 rounded-xl font-body-md font-semibold transition-colors">
                            Continuer vers le traitement
                            <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                    @endif
                </div>

                {{-- Validation des ambiguïtés --}}
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6 mb-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-primary">{{ count($ambiguities) > 0 ? 'report' : 'task_alt' }}</span>
                        <h2 class="font-h2 text-h2 text-on-surface">Validation des ambiguïtés</h2>
                    </div>

                    @if ($isValidated)
                        <p class="text-body-md text-on-surface-variant">
                            Aucune ambiguïté restante : les corrections ont été appliquées au plan.
                        </p>
                    @else
                        <form method="POST" action="{{ route('documents.validate', $document) }}">
                            @csrf

                            @if (count($ambiguities) > 0)
                                <p class="text-body-md text-on-surface-variant mb-4">
                                    Certaines numérotations ne correspondent pas au niveau détecté.
                                    Corrigez si nécessaire, puis validez.
                                </p>

                                <div class="space-y-3 mb-6">
                                    @foreach ($ambiguities as $ambiguity)
                                        <div class="flex flex-col md:flex-row md:items-center gap-4 border border-outline-variant rounded-lg p-4 bg-surface-container-low">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-body-md font-semibold break-words">{{ $ambiguity['texte'] }}</p>
                                                <p class="font-caption text-caption text-on-surface-variant mt-1">
                                                    {{ $ambiguity['raison'] }}
                                                    <span class="inline-flex items-center gap-1 bg-secondary-container text-on-secondary-container rounded-full px-2 py-0.5 ml-1">
                                                        <span class="material-symbols-outlined text-[14px]">lightbulb</span>
                                                        Suggéré : niveau {{ $ambiguity['niveau_suggere'] }}
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="md:w-56 shrink-0">
                                                <label class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">
                                                    Niveau {{ $ambiguity['niveau_detecte'] }} → correction
                                                </label>
                                                <select name="corrections[{{ $ambiguity['id'] }}]"
                                                        class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg px-3 py-2 text-body-md focus:outline-none focus:ring-2 focus:ring-primary">
                                                    <option value="1" @selected(($ambiguity['niveau_detecte'] ?? null) === 1)>Titre (niveau 1)</option>
                                                    <option value="2" @selected(($ambiguity['niveau_detecte'] ?? null) === 2)>Sous-titre (niveau 2)</option>
                                                    <option value="3">Sous-titre (niveau 3)</option>
                                                    <option value="remove">Retirer du plan</option>
                                                </select>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-body-md text-on-surface-variant mb-4">
                                    Aucune ambiguïté détectée. La structure semble cohérente.
                                </p>
                            @endif

                            <div class="flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-primary hover:bg-primary-fixed-variant text-on-primary px-6 py-3 rounded-xl font-body-md font-semibold transition-colors">
                                    <span class="material-symbols-outlined">rocket_launch</span>
                                    Valider et lancer le traitement
                                </button>
                            </div>
                        </form>
                    @endif
                </section>

                {{-- Hiérarchie des titres --}}
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6 mb-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-primary">account_tree</span>
                        <h2 class="font-h2 text-h2 text-on-surface">Hiérarchie des titres détectée</h2>
                    </div>
                    @if ($titreCount > 0)
                        <p class="font-label-mono text-label-mono text-secondary uppercase mb-3">{{ $titreCount }} titres détectés</p>
                        <div class="bg-surface-container-low rounded-xl p-5 font-doc-preview text-doc-preview">
                            @foreach ($titres as $titre)
                                <h3 class="font-bold text-on-surface mb-2">{{ $titre['texte'] ?? '' }}</h3>
                            @endforeach
                            @foreach ($sousTitres as $sousTitre)
                                <h4 class="font-semibold text-on-surface-variant mb-2 ml-6">{{ $sousTitre['texte'] ?? '' }}</h4>
                            @endforeach
                        </div>
                    @else
                        <p class="text-body-md text-on-surface-variant">Aucun titre détecté dans ce document.</p>
                    @endif
                </section>

                {{-- En-têtes et pieds de page --}}
                @if (count($enTetes) > 0 || count($piedsDePage) > 0)
                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6 mb-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="material-symbols-outlined text-primary">vertical_align_top</span>
                            <h2 class="font-h2 text-h2 text-on-surface">En-têtes et pieds de page</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p class="font-label-mono text-label-mono text-secondary uppercase mb-2">En-têtes</p>
                                <ul class="space-y-1">
                                    @forelse ($enTetes as $enTete)
                                        <li class="text-body-md">{{ $enTete['texte'] ?? '' }}</li>
                                    @empty
                                        <li class="text-body-md text-on-surface-variant">Aucun en-tête.</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <p class="font-label-mono text-label-mono text-secondary uppercase mb-2">Pieds de page</p>
                                <ul class="space-y-1">
                                    @forelse ($piedsDePage as $pied)
                                        <li class="text-body-md">{{ $pied['texte'] ?? '' }}</li>
                                    @empty
                                        <li class="text-body-md text-on-surface-variant">Aucun pied de page.</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Légendes --}}
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6 mb-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-primary">badge</span>
                        <h2 class="font-h2 text-h2 text-on-surface">Légendes détectées (figures, tableaux, annexes…)</h2>
                    </div>
                    @if (count($legends) > 0)
                        <p class="font-label-mono text-label-mono text-secondary uppercase mb-3">{{ count($legends) }} légendes détectées</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-outline-variant">
                                        <th class="py-2 pr-4 font-label-mono text-label-mono text-secondary uppercase">Ligne</th>
                                        <th class="py-2 pr-4 font-label-mono text-label-mono text-secondary uppercase">Type</th>
                                        <th class="py-2 pr-4 font-label-mono text-label-mono text-secondary uppercase">N°</th>
                                        <th class="py-2 font-label-mono text-label-mono text-secondary uppercase">Libellé</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($legends as $legend)
                                        <tr class="border-b border-outline-variant/50">
                                            <td class="py-2 pr-4 text-caption text-on-surface-variant">{{ $legend['line'] }}</td>
                                            <td class="py-2 pr-4 text-body-md">{{ $legend['type'] }}</td>
                                            <td class="py-2 pr-4 text-body-md">{{ $legend['number'] }}</td>
                                            <td class="py-2 text-body-md">{{ $legend['label'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-body-md text-on-surface-variant">Aucune légende détectée.</p>
                    @endif
                </section>

                {{-- Couverture personnalisée (Phase 3, optionnel) --}}
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-primary">styler</span>
                        <h2 class="font-h2 text-h2 text-on-surface">Couverture personnalisée (optionnel)</h2>
                    </div>
                    <p class="text-body-md text-on-surface-variant mb-4">
                        Fournissez une couverture d'exemple (<code class="font-label-mono">.docx</code>) :
                        FORMADOC détecte les zones (nom, titre, encadrant, date) et les remplace en
                        conservant la structure et les styles.
                    </p>
                    <form method="POST" action="{{ route('documents.generate-cover', $document) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="cover" class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">Couverture d'exemple (.docx)</label>
                                <input type="file" class="block w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-2 text-body-md @error('cover') border-error @enderror"
                                       id="cover" name="cover" accept=".docx" required>
                                @error('cover')
                                    <p class="font-caption text-caption text-error mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="nom" class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">Nom</label>
                                <input type="text" class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-2 text-body-md focus:outline-none focus:ring-2 focus:ring-primary"
                                       id="nom" name="nom" placeholder="Ex : JEAN DUPONT">
                            </div>
                            <div>
                                <label for="titre" class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">Titre</label>
                                <input type="text" class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-2 text-body-md focus:outline-none focus:ring-2 focus:ring-primary"
                                       id="titre" name="titre" placeholder="Ex : CONCEPTION D'UNE APPLICATION WEB">
                            </div>
                            <div>
                                <label for="encadrant" class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">Encadrant</label>
                                <input type="text" class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-2 text-body-md focus:outline-none focus:ring-2 focus:ring-primary"
                                       id="encadrant" name="encadrant" placeholder="Ex : Dr. MARTIN">
                            </div>
                            <div>
                                <label for="date" class="font-label-mono text-label-mono text-secondary uppercase mb-1 block">Date / Année académique</label>
                                <input type="text" class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-2 text-body-md focus:outline-none focus:ring-2 focus:ring-primary"
                                       id="date" name="date" placeholder="Ex : 2025-2026">
                            </div>
                            <div class="md:col-span-2 flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-secondary hover:bg-on-secondary-container text-on-secondary px-5 py-3 rounded-xl font-body-md font-semibold transition-colors">
                                    <span class="material-symbols-outlined">style</span>
                                    Générer avec couverture
                                </button>
                            </div>
                        </div>
                    </form>
                </section>
            @endif

            {{-- Actions secondaires --}}
            <div class="flex flex-wrap gap-3 mt-8">
                <a href="{{ route('documents.create') }}"
                   class="inline-flex items-center gap-2 text-primary hover:bg-surface-container-high px-4 py-2 rounded-lg font-body-md transition-colors">
                    <span class="material-symbols-outlined">add</span>
                    Analyser un autre rapport
                </a>
                <a href="{{ route('feedback.form') }}"
                   class="inline-flex items-center gap-2 text-secondary hover:bg-surface-container-high px-4 py-2 rounded-lg font-body-md transition-colors">
                    <span class="material-symbols-outlined">rate_review</span>
                    Donner mon avis
                </a>
            </div>

        </div>
    </div>
@endsection
