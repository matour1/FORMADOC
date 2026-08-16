@extends('layouts.app')

@section('title', 'Analyser un rapport')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 1])

    <div class="md:ml-64">
        <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

            {{-- En-tête de page --}}
            <div class="mb-8 md:mb-12">
                <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Étape 1 / 4 — Upload</p>
                <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                    Analyser un rapport
                </h1>
                <p class="font-body-md text-body-md text-on-surface-variant max-w-2xl">
                    Déposez votre rapport (stage, projet ou mémoire) au format
                    <strong>.docx</strong>, <strong>.doc</strong> ou <strong>.txt</strong>.
                    FORMADOC détecte automatiquement sa structure : titres, hiérarchie,
                    en-têtes, pieds de page et légendes.
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

            {{-- Carte d'upload (bento) --}}
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 md:p-10 max-w-3xl">
                <form action="{{ route('documents.upload') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <label for="document"
                           class="block border-2 border-dashed border-outline-variant hover:border-primary rounded-xl p-10 md:p-16 text-center cursor-pointer transition-colors bg-surface-container-low/50">
                        <span class="material-symbols-outlined text-[64px] text-primary">cloud_upload</span>
                        <p class="font-body-md text-body-md text-on-surface mt-4 mb-1">
                            Glissez-déposez votre rapport ici
                        </p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            ou cliquez pour parcourir vos fichiers
                        </p>
                        <p class="font-label-mono text-label-mono text-outline mt-4">
                            .DOCX · .DOC · .TXT — 50 Mo max
                        </p>
                        <input type="file"
                               class="hidden @error('document') is-invalid @enderror"
                               id="document"
                               name="document"
                               accept=".docx,.doc,.txt"
                               required>
                    </label>
                    @error('document')
                        <p class="font-caption text-caption text-error mt-2">{{ $message }}</p>
                    @enderror

                    <div class="flex justify-end mt-6">
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-primary hover:bg-primary-fixed-variant text-on-primary px-6 py-3 rounded-xl font-body-md text-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined">manage_search</span>
                            Analyser le document
                        </button>
                    </div>
                </form>
            </div>

            {{-- Aperçu du parcours --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-8 max-w-3xl">
                @foreach ([
                    ['fact_check', 'Validation', 'Vérifiez et corrigez la structure détectée'],
                    ['auto_awesome', 'Traitement', 'Mise en forme selon le gabarit institutionnel'],
                    ['download', 'Export', 'Téléchargez votre DOCX reconstruit'],
                ] as $step)
                    <div class="bg-surface-container-low border border-outline-variant rounded-xl p-4">
                        <span class="material-symbols-outlined text-primary">{{ $step[0] }}</span>
                        <p class="font-body-md text-body-md font-semibold mt-2">{{ $step[1] }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ $step[2] }}</p>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
@endsection
