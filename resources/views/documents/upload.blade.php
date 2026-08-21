@extends('layouts.app')

@section('title', 'Analyser un rapport')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 1])

    <div class="max-w-3xl">

        {{-- En-tête de page --}}
        <div class="page-header">
            <div>
                <span class="eyebrow">Étape 1 / 4 — Upload</span>
                <h1>Analyser un rapport</h1>
                <p>
                    Déposez votre rapport (stage, projet ou mémoire) au format
                    <strong>.docx</strong>, <strong>.doc</strong> ou <strong>.txt</strong>.
                    FORMADOC détecte automatiquement sa structure : titres, hiérarchie,
                    en-têtes, pieds de page, tableaux, images et légendes.
                </p>
            </div>
        </div>

        {{-- Carte d'upload --}}
        <div class="card">
            {{-- Aperçu du fichier sélectionné (masqué par défaut) --}}
            <div id="file-preview" class="file-preview hidden">
                <div class="fp-icon" id="file-preview-icon">
                    <i data-lucide="file-text"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p id="file-preview-name" class="fp-name"></p>
                    <p id="file-preview-meta" class="fp-meta"></p>
                    <div id="file-preview-content" class="fp-content hidden">
                        <span class="eyebrow" style="margin-bottom:.5rem">Aperçu du contenu</span>
                        <pre id="file-preview-text"></pre>
                    </div>
                </div>
                <button type="button" onclick="resetFileSelection()"
                        class="btn btn-ghost btn-sm" aria-label="Retirer le fichier">
                    <i data-lucide="x" style="width:15px;height:15px"></i> Retirer
                </button>
            </div>

            <form id="upload-form" action="{{ route('documents.upload') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <label for="document" class="dropzone">
                    <span class="dz-icon"><i data-lucide="cloud-upload" style="width:26px;height:26px"></i></span>
                    <span class="dz-title">Glissez-déposez votre rapport ici</span>
                    <span class="dz-sub">ou cliquez pour parcourir vos fichiers</span>
                    <span class="dz-formats">.DOCX · .DOC · .TXT — 50 Mo max</span>
                    <input type="file"
                           class="sr-only @error('document') is-invalid @enderror"
                           id="document"
                           name="document"
                           accept=".docx,.doc,.txt"
                           required>
                </label>
                @error('document')
                    <span class="field-error">{{ $message }}</span>
                @enderror

                {{-- Choix de la méthode d'analyse des titres --}}
                <div style="margin-top:1.6rem">
                    <p style="font-weight:600;font-size:.92rem;margin-bottom:.25rem">Méthode de détection des titres</p>
                    <p style="color:var(--color-text-muted);font-size:.8rem;margin-bottom:.9rem">
                        Choisissez comment FORMADOC identifie les titres de votre rapport.
                    </p>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem" role="radiogroup" aria-label="Méthode de détection des titres">
                        {{-- Méthode Regex (recommandée) --}}
                        <label class="radio-card" for="method-regex">
                            <input type="radio" name="title_method" id="method-regex" value="regex" checked>
                            <span class="rc-icon"><i data-lucide="file-check" style="width:17px;height:17px"></i></span>
                            <span>
                                <strong>Analyse rapide (Regex)</strong>
                                <small>Détection par les styles Word (Heading 1-3, tailles, gras) complétée par des motifs regex (numérotation « 1. », « 1.1 », mots-clés CHAPITRE, INTRODUCTION…). <strong>Rapide et hors-ligne.</strong></small>
                            </span>
                        </label>

                        {{-- Méthode IA --}}
                        <label class="radio-card" for="method-ia">
                            <input type="radio" name="title_method" id="method-ia" value="ia">
                            <span class="rc-icon"><i data-lucide="bot" style="width:17px;height:17px"></i></span>
                            <span>
                                <strong>Analyse par IA</strong>
                                <small>Un modèle de langue (DeepSeek) lit l'intégralité du texte pour classer chaque élément : titres, sous-titres, en-têtes, pieds de page, tableaux, images. <strong>Plus précise mais plus lente</strong> (quelques minutes selon la taille du rapport).</small>
                            </span>
                        </label>
                    </div>
                </div>

                {{-- Assistance IA facultative (post-processeur correctif) --}}
                <div style="margin-top:1.2rem">
                    <label for="use_ai" class="check-card">
                        <input type="checkbox" name="use_ai" id="use_ai" value="1">
                        <span class="cc-icon"><i data-lucide="sparkles" style="width:17px;height:17px"></i></span>
                        <span>
                            <strong>Utiliser l'assistance IA</strong>
                            <small>L'IA peut améliorer la détection des listes et lever les ambiguïtés, mais peut ralentir le traitement.</small>
                            <span class="cc-note">Non cochée par défaut : aucune donnée n'est envoyée à un service externe. L'analyse déterministe reste entièrement fonctionnelle sans IA.</span>
                        </span>
                    </label>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-top:1.5rem">
                    <button type="submit" id="upload-submit" class="btn btn-primary">
                        <i data-lucide="search-check" id="submit-icon" style="width:17px;height:17px"></i>
                        <span id="submit-label">Analyser le document</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- Animation d'analyse (masquée, affichée pendant le traitement) --}}
        <div id="analysis-panel" class="card analysis-panel hidden">
            <div class="analysis-head">
                <div class="pulse-icon"><i data-lucide="wand-2" style="width:24px;height:24px"></i></div>
                <div>
                    <h2 style="font-size:1.1rem;font-weight:700" id="analysis-title">Analyse en cours…</h2>
                    <p style="color:var(--color-text-muted);font-size:.8rem" id="analysis-subtitle">
                        Veuillez patienter pendant que FORMADOC examine votre rapport.
                    </p>
                </div>
            </div>

            <div class="loading-track"><div class="loading-fill"></div></div>

            {{-- Journal d'analyse : étapes distinctes --}}
            <div id="analysis-log" class="analysis-log">
                <p><span class="log-prompt">$</span><span class="log-line">Préparation de l'analyse…</span></p>
            </div>
        </div>

        {{-- Aperçu du parcours --}}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:1.5rem">
            @foreach ([
                ['check-square', 'Validation', 'Vérifiez et corrigez la structure détectée'],
                ['wand-2', 'Traitement', 'Mise en forme selon le gabarit institutionnel'],
                ['download', 'Export', 'Téléchargez votre DOCX reconstruit'],
            ] as $step)
                <div class="card" style="padding:1.1rem">
                    <i data-lucide="{{ $step[0] }}" style="width:20px;height:20px;color:var(--color-primary)"></i>
                    <p style="font-weight:600;font-size:.9rem;margin-top:.55rem">{{ $step[1] }}</p>
                    <p style="color:var(--color-text-muted);font-size:.78rem">{{ $step[2] }}</p>
                </div>
            @endforeach
        </div>

    </div>

    @push('scripts')
    <script>
        (function () {
            'use strict';

            const input = document.getElementById('document');
            const preview = document.getElementById('file-preview');
            const previewName = document.getElementById('file-preview-name');
            const previewMeta = document.getElementById('file-preview-meta');
            const previewText = document.getElementById('file-preview-text');
            const previewContent = document.getElementById('file-preview-content');
            const previewIcon = document.getElementById('file-preview-icon');

            // ── Aperçu du fichier sélectionné ──────────────────────────────────
            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' o';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
                return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
            }

            function showPreview(file) {
                if (!file) return;

                previewName.textContent = file.name;
                previewMeta.textContent = formatSize(file.size) + ' · ' + (file.type || 'inconnu');

                if (file.name.toLowerCase().endsWith('.txt')) {
                    previewIcon.innerHTML = '<i data-lucide="file-text"></i>';
                } else {
                    previewIcon.innerHTML = '<i data-lucide="file-text"></i>';
                }
                if (window.lucide) lucide.createIcons();

                // Aperçu du contenu pour les .txt (petits fichiers uniquement)
                if (file.name.toLowerCase().endsWith('.txt') && file.size < 100000) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        previewText.textContent = e.target.result.slice(0, 4000);
                        previewContent.classList.remove('hidden');
                    };
                    reader.readAsText(file);
                } else {
                    previewContent.classList.add('hidden');
                }

                preview.classList.remove('hidden');
            }

            window.resetFileSelection = function () {
                input.value = '';
                preview.classList.add('hidden');
            };

            input.addEventListener('change', function () {
                showPreview(input.files[0]);
            });

            // ── Soumission AJAX + animation d'analyse ──────────────────────────
            const form = document.getElementById('upload-form');
            const panel = document.getElementById('analysis-panel');
            const logEl = document.getElementById('analysis-log');
            const titleEl = document.getElementById('analysis-title');
            const subtitleEl = document.getElementById('analysis-subtitle');

            function appendLog(line, icon) {
                const p = document.createElement('p');
                p.innerHTML = '<span class="log-prompt">$</span>'
                    + '<span class="log-line">' + line + '</span>';
                logEl.appendChild(p);
                logEl.scrollTop = logEl.scrollHeight;
            }

            // Étapes affichées pendant la requête (adaptées à la méthode + IA)
            function startAnimation(method, useAi) {
                const steps = [];

                if (method === 'ia') {
                    steps.push(
                        ['Analyse des styles et du texte du document…', 'description'],
                        ['Envoi du contenu à l\'IA (DeepSeek)…', 'smart_toy'],
                        ['Classification des titres et sous-titres par l\'IA…', 'psychology'],
                        ['Détection des en-têtes, pieds de page, tableaux et images…', 'grid_on'],
                        ['Détection des légendes (figures, tableaux)…', 'image'],
                        ['Fusion des résultats et normalisation de la structure…', 'merge']
                    );
                } else if (useAi) {
                    steps.push(
                        ['Analyse des styles Word (Heading, tailles, gras)…', 'format_bold'],
                        ['Détection des titres par motifs regex…', 'rule'],
                        ['Détection des en-têtes, pieds de page, tableaux et images…', 'grid_on'],
                        ['Détection des légendes (figures, tableaux)…', 'image'],
                        ['Envoi des éléments ambigus à l\'IA pour correction ciblée…', 'smart_toy'],
                        ['Correction des listes et levée des ambiguïtés…', 'psychology'],
                        ['Fusion des résultats et normalisation de la structure…', 'merge']
                    );
                } else {
                    steps.push(
                        ['Analyse des styles Word (Heading, tailles, gras)…', 'format_bold'],
                        ['Détection des titres par motifs regex…', 'rule'],
                        ['Détection des en-têtes, pieds de page, tableaux et images…', 'grid_on'],
                        ['Détection des légendes (figures, tableaux)…', 'image'],
                        ['Fusion des résultats et normalisation de la structure…', 'merge']
                    );
                }

                logEl.innerHTML = '<p><span class="log-prompt">$</span><span class="log-line">Préparation de l\'analyse…</span></p>';

                let index = 0;
                let timer = null;
                let done = false;

                function step() {
                    if (done) return;
                    if (index < steps.length) {
                        appendLog(steps[index][0], steps[index][1]);
                        index++;
                        timer = setTimeout(step, 1400);
                    } else {
                        appendLog('Finalisation de la structure…', 'task_alt');
                    }
                }

                timer = setTimeout(step, 900);

                return {
                    finish: function () {
                        done = true;
                        if (timer) clearTimeout(timer);
                    }
                };
            }

            form.addEventListener('submit', function (e) {
                const file = input.files[0];
                if (!file) {
                    return; // Le champ required laisse le navigateur gérer.
                }

                // Affiche l'animation + désactive le formulaire
                e.preventDefault();
                form.classList.add('hidden');
                panel.classList.remove('hidden');
                titleEl.textContent = 'Analyse en cours…';
                subtitleEl.textContent = 'Veuillez patienter pendant que FORMADOC examine votre rapport.';

                const method = document.querySelector('input[name="title_method"]:checked').value;
                const useAi = document.getElementById('use_ai')?.checked || false;
                const anim = startAnimation(method, useAi);

                const fd = new FormData(form);

                fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (resp) {
                    anim.finish();

                    if (resp.redirected) {
                        window.location.href = resp.url;
                        return;
                    }
                    if (resp.ok) {
                        window.location.reload();
                        return;
                    }
                    // Erreur : message + restauration
                    return resp.text().then(function (html) {
                        const m = html.match(/<div class="[^"]*text-error[^"]*"[^>]*>([\s\S]*?)<\/div>/);
                        const message = m ? m[1].replace(/<[^>]+>/g, ' ').trim() : 'Erreur lors de l\'analyse du document.';
                        appendLog('Échec : ' + message, 'error');
                        titleEl.textContent = 'Analyse impossible';
                        subtitleEl.textContent = 'Veuillez réessayer.';
                    });
                }).catch(function (err) {
                    anim.finish();
                    appendLog('Erreur réseau : ' + err.message, 'error');
                    titleEl.textContent = 'Analyse impossible';
                    subtitleEl.textContent = 'Veuillez réessayer.';
                });
            });

            // Réaffiche l'aperçu si le navigateur restaure la sélection
            if (input.files && input.files.length > 0) {
                showPreview(input.files[0]);
            }
        })();
    </script>
    @endpush
@endsection
