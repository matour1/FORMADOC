@extends('layouts.app')

@section('title', 'Traitement en cours')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 3, 'document' => $document])

    <div class="md:ml-64">
        <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop flex justify-center">

            <div class="w-full max-w-2xl">
                {{-- En-tête --}}
                <div class="text-center mb-8 md:mb-12">
                    <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Étape 3 / 4 — Traitement</p>
                    <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                        Traitement en cours
                    </h1>
                    <p class="font-body-md text-body-md text-on-surface-variant">
                        FORMADOC met en forme <strong>{{ $document->filename }}</strong> selon le
                        gabarit institutionnel. Veuillez patienter quelques instants…
                    </p>
                </div>

                {{-- Animation de traitement --}}
                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-8 md:p-10 flex flex-col items-center mb-6">
                    <div class="relative w-20 h-20 mb-6">
                        <div class="absolute inset-0 rounded-full bg-primary-fixed opacity-40 pulse-icon"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-[48px]">auto_awesome</span>
                        </div>
                    </div>

                    <div class="w-full max-w-sm loading-track mb-8">
                        <div class="loading-fill"></div>
                    </div>

                    {{-- Journal de traitement (terminal) --}}
                    <div id="processing-log"
                         class="w-full bg-inverse-surface text-surface-container-lowest rounded-xl p-5 font-label-mono text-label-mono text-left space-y-2 min-h-[160px]">
                        <p><span class="text-primary-fixed-dim">$</span> Initialisation du traitement…</p>
                    </div>
                </div>

                {{-- Repli / actions --}}
                <div class="flex flex-col items-center gap-3">
                    <p id="processing-hint" class="font-caption text-caption text-on-surface-variant">
                        Vous allez être redirigé automatiquement…
                    </p>
                    <a href="{{ route('documents.export', $document) }}"
                       class="inline-flex items-center gap-2 text-primary hover:bg-surface-container-high px-5 py-2 rounded-lg font-body-md font-semibold transition-colors">
                        Continuer vers l'export
                        <span class="material-symbols-outlined">arrow_forward</span>
                    </a>
                </div>
            </div>

        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            const logEl = document.getElementById('processing-log');
            const hintEl = document.getElementById('processing-hint');
            const lines = [
                'Analyse du document…',
                'Détection de la structure : OK',
                'Validation des ambiguïtés : OK',
                'Application du gabarit institutionnel…',
                'Génération du DOCX reconstruit…',
                'Préparation du téléchargement…'
            ];
            const exportUrl = @json(route('documents.export', $document));

            let index = 0;
            function appendLine() {
                if (index < lines.length) {
                    const p = document.createElement('p');
                    p.innerHTML = '<span class="text-primary-fixed-dim">$</span> ' + lines[index];
                    logEl.appendChild(p);
                    logEl.scrollTop = logEl.scrollHeight;
                    index++;
                    setTimeout(appendLine, 550);
                } else {
                    if (hintEl) {
                        hintEl.textContent = 'Traitement terminé. Redirection en cours…';
                    }
                    setTimeout(function () {
                        window.location.href = exportUrl;
                    }, 600);
                }
            }
            setTimeout(appendLine, 700);
        })();
    </script>
    @endpush
@endsection
