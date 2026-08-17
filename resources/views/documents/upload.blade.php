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
                    en-têtes, pieds de page, tableaux, images et légendes.
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
                {{-- Aperçu du fichier sélectionné (masqué par défaut) --}}
                <div id="file-preview"
                     class="hidden border border-outline-variant rounded-xl p-5 mb-6 bg-surface-container-low">
                    <div class="flex items-center gap-4">
                        <span class="material-symbols-outlined text-[48px] text-primary" id="file-preview-icon">description</span>
                        <div class="min-w-0 flex-1">
                            <p id="file-preview-name" class="font-body-md font-semibold break-words"></p>
                            <p id="file-preview-meta" class="font-caption text-caption text-on-surface-variant"></p>
                        </div>
                        <button type="button" onclick="resetFileSelection()"
                                class="shrink-0 inline-flex items-center gap-1 text-secondary hover:bg-surface-container-high px-3 py-2 rounded-lg font-body-md transition-colors"
                                aria-label="Retirer le fichier">
                            <span class="material-symbols-outlined text-[20px]">close</span>
                            Retirer
                        </button>
                    </div>
                    <div id="file-preview-content" class="mt-4 hidden">
                        <p class="font-label-mono text-label-mono text-secondary uppercase mb-2">Aperçu du contenu</p>
                        <div id="file-preview-text"
                             class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4 font-doc-preview text-doc-preview max-h-64 overflow-y-auto whitespace-pre-wrap text-on-surface"></div>
                    </div>
                </div>

                <form id="upload-form" action="{{ route('documents.upload') }}" method="POST" enctype="multipart/form-data">
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

                    {{-- Choix de la méthode d'analyse des titres --}}
                    <div class="mt-8">
                        <p class="font-body-md font-semibold text-on-surface mb-1">Méthode de détection des titres</p>
                        <p class="font-caption text-caption text-on-surface-variant mb-4">
                            Choisissez comment FORMADOC identifie les titres de votre rapport.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" role="radiogroup" aria-label="Méthode de détection des titres">
                            {{-- Méthode Regex (recommandée) --}}
                            <label class="relative block border-2 border-primary rounded-xl p-5 cursor-pointer transition-colors bg-primary-fixed/30 has-[:checked]:border-primary"
                                   for="method-regex">
                                <input type="radio" name="title_method" id="method-regex" value="regex" checked
                                       class="sr-only peer">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-primary">rule</span>
                                    <div>
                                        <p class="font-body-md font-semibold">Analyse rapide (Regex)</p>
                                        <p class="font-caption text-caption text-on-surface-variant mt-1">
                                            Détection par les styles Word (Heading 1-3, tailles, gras)
                                            complétée par des motifs regex (numérotation « 1. », « 1.1 »,
                                            mots-clés CHAPITRE, INTRODUCTION…). <strong>Rapide et hors-ligne.</strong>
                                        </p>
                                        <p class="font-caption text-caption text-primary mt-2">
                                            Si aucun titre n'est trouvé, l'IA prend automatiquement le relais.
                                        </p>
                                    </div>
                                </div>
                            </label>

                            {{-- Méthode IA --}}
                            <label class="relative block border-2 border-outline-variant rounded-xl p-5 cursor-pointer transition-colors hover:border-primary has-[:checked]:border-primary"
                                   for="method-ia">
                                <input type="radio" name="title_method" id="method-ia" value="ia"
                                       class="sr-only peer">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-primary">smart_toy</span>
                                    <div>
                                        <p class="font-body-md font-semibold">Analyse par IA</p>
                                        <p class="font-caption text-caption text-on-surface-variant mt-1">
                                            Un modèle de langue (DeepSeek) lit l'intégralité du texte pour
                                            classer chaque élément : titres, sous-titres, en-têtes, pieds de
                                            page, tableaux, images. <strong>Plus précise mais plus lente</strong>
                                            (quelques minutes selon la taille du rapport).
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end mt-6">
                        <button type="submit" id="upload-submit"
                                class="inline-flex items-center gap-2 bg-primary hover:bg-primary-fixed-variant text-on-primary px-6 py-3 rounded-xl font-body-md text-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined" id="submit-icon">manage_search</span>
                            <span id="submit-label">Analyser le document</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Animation d'analyse (masquée, affichée pendant le traitement) --}}
            <div id="analysis-panel"
                 class="hidden bg-surface-container-lowest border border-outline-variant rounded-xl p-8 md:p-10 max-w-3xl mt-8">
                <div class="flex items-center gap-4 mb-6">
                    <div class="relative w-14 h-14 shrink-0">
                        <div class="absolute inset-0 rounded-full bg-primary-fixed opacity-40 pulse-icon"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-[32px]">auto_awesome</span>
                        </div>
                    </div>
                    <div>
                        <h2 class="font-h2 text-h2 text-on-surface" id="analysis-title">Analyse en cours…</h2>
                        <p class="font-caption text-caption text-on-surface-variant" id="analysis-subtitle">
                            Veuillez patienter pendant que FORMADOC examine votre rapport.
                        </p>
                    </div>
                </div>

                <div class="w-full loading-track mb-6">
                    <div class="loading-fill"></div>
                </div>

                {{-- Journal d'analyse : étapes distinctes --}}
                <div id="analysis-log"
                     class="w-full bg-inverse-surface text-surface-container-lowest rounded-xl p-5 font-label-mono text-label-mono text-left space-y-2 min-h-[180px] max-h-72 overflow-y-auto">
                    <p><span class="text-primary-fixed-dim">$</span> Préparation de l'analyse…</p>
                </div>
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
                    previewIcon.textContent = 'article';
                } else {
                    previewIcon.textContent = 'description';
                }

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
                p.innerHTML = '<span class="text-primary-fixed-dim">$</span> '
                    + (icon ? '<span class="material-symbols-outlined align-middle text-[16px] mr-1">' + icon + '</span>' : '')
                    + line;
                logEl.appendChild(p);
                logEl.scrollTop = logEl.scrollHeight;
            }

            // Étapes affichées pendant la requête (adaptées à la méthode)
            function startAnimation(method) {
                const steps = method === 'ia' ? [
                    ['Analyse des styles et du texte du document…', 'description'],
                    ['Envoi du contenu à l\'IA (DeepSeek)…', 'smart_toy'],
                    ['Classification des titres et sous-titres par l\'IA…', 'psychology'],
                    ['Détection des en-têtes, pieds de page, tableaux et images…', 'grid_on'],
                    ['Détection des légendes (figures, tableaux)…', 'image'],
                    ['Fusion des résultats et normalisation de la structure…', 'merge']
                ] : [
                    ['Analyse des styles Word (Heading, tailles, gras)…', 'format_bold'],
                    ['Détection des titres par motifs regex…', 'rule'],
                    ['Détection des en-têtes, pieds de page, tableaux et images…', 'grid_on'],
                    ['Détection des légendes (figures, tableaux)…', 'image'],
                    ['Fusion des résultats et normalisation de la structure…', 'merge']
                ];

                logEl.innerHTML = '<p><span class="text-primary-fixed-dim">$</span> Préparation de l\'analyse…</p>';

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
                const anim = startAnimation(method);

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
