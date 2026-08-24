@extends('layouts.app')

@section('title', 'Traitement en cours')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 3, 'document' => $document])

    <div class="flex justify-center">
        <div class="max-w-2xl" style="width:100%">
            {{-- En-tête --}}
            <div class="page-header" style="text-align:center;align-items:center">
                <div>
                    <span class="eyebrow">Étape 3 / 4 — Traitement</span>
                    <h1>Traitement en cours</h1>
                    <p>
                        FORMADOC met en forme <strong>{{ $document->filename }}</strong> selon le
                        gabarit choisi. Veuillez patienter quelques instants…
                    </p>
                </div>
            </div>

            {{-- Animation de traitement --}}
            <div class="card" style="padding:2.2rem 2.4rem;display:flex;flex-direction:column;align-items:center;margin-bottom:1.5rem">
                <div style="position:relative;width:80px;height:80px;margin-bottom:1.6rem">
                    <div class="pulse-icon" style="position:absolute;inset:0;border-radius:50%;background:var(--color-primary);opacity:.35"></div>
                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
                        <i data-lucide="wand-2" style="width:42px;height:42px;color:var(--color-primary)"></i>
                    </div>
                </div>

                <div class="loading-track" style="width:100%;max-width:26rem;margin-bottom:2rem">
                    <div class="loading-fill"></div>
                </div>

                {{-- Temps écoulé + message « toujours en cours » --}}
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.4rem;font-family:var(--font-mono);font-size:.78rem;color:var(--color-text-muted)">
                    <span class="processing-pulse" style="width:8px;height:8px;border-radius:50%;background:var(--color-primary);display:inline-block" aria-hidden="true"></span>
                    <span id="processing-elapsed" role="timer" aria-live="off">0s</span>
                    <span>Toujours en cours…</span>
                </div>

                {{-- Journal de traitement (terminal) --}}
                <div id="processing-log"
                     style="width:100%;background:var(--color-text);color:var(--color-surface);border-radius:var(--radius-sm);padding:1.2rem 1.3rem;font-family:var(--font-mono);font-size:.78rem;text-align:left;min-height:160px;line-height:1.7">
                    <p><span class="log-prompt">$</span> Initialisation du traitement…</p>
                </div>
            </div>

            {{-- Repli / actions --}}
            <div style="display:flex;flex-direction:column;align-items:center;gap:.7rem">
                <p id="processing-hint" style="font-size:.8rem;color:var(--color-text-muted)">
                    Vous allez être redirigé automatiquement…
                </p>
                <a href="{{ route('documents.export', $document) }}" class="btn btn-ghost">
                    Continuer vers l'export
                    <i data-lucide="arrow-right" style="width:16px;height:16px"></i>
                </a>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            const logEl = document.getElementById('processing-log');
            const hintEl = document.getElementById('processing-hint');
            const elapsedEl = document.getElementById('processing-elapsed');
            const lines = [
                'Analyse du document…',
                'Détection de la structure : OK',
                'Validation des ambiguïtés : OK',
                'Application du gabarit choisi…',
                'Génération du DOCX reconstruit…',
                'Préparation du téléchargement…'
            ];
            const exportUrl = @json(route('documents.export', $document));

            // Temps écoulé (mise à jour chaque seconde)
            const start = Date.now();
            const elapsedTimer = setInterval(() => {
                if (elapsedEl) {
                    const seconds = Math.floor((Date.now() - start) / 1000);
                    elapsedEl.textContent = seconds + 's';
                }
            }, 1000);

            let index = 0;
            function appendLine() {
                if (index < lines.length) {
                    const p = document.createElement('p');
                    p.innerHTML = '<span class="log-prompt">$</span> ' + lines[index];
                    logEl.appendChild(p);
                    logEl.scrollTop = logEl.scrollHeight;
                    index++;
                    setTimeout(appendLine, 550);
                } else {
                    if (hintEl) {
                        hintEl.textContent = 'Traitement terminé. Redirection en cours…';
                    }
                    clearInterval(elapsedTimer);
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
