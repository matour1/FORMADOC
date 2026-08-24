@extends('layouts.app')

@section('title', 'Nouveau document')

@section('content')
    <div class="max-w-4xl">

        {{-- En-tête de page --}}
        <div class="page-header">
            <div>
                <span class="eyebrow">Traitement</span>
                <h1>Nouveau document</h1>
                <p>Téléversez un fichier, choisissez un gabarit, puis lancez l'analyse.</p>
            </div>
        </div>

        {{-- Étapes de création : Fichier → Gabarit → Options IA → Aperçu --}}
        <div class="upload-steps" aria-label="Étapes de création d'un document">
            <div class="upload-step active"><span class="num">1</span> Fichier</div>
            <div class="upload-step-sep"></div>
            <div class="upload-step"><span class="num">2</span> Gabarit</div>
            <div class="upload-step-sep"></div>
            <div class="upload-step"><span class="num">3</span> Options IA</div>
            <div class="upload-step-sep"></div>
            <div class="upload-step"><span class="num">4</span> Aperçu</div>
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
                    <p id="file-preview-hint" class="fp-hint hidden"></p>
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

                <div style="display:flex;justify-content:flex-end;margin-top:1.5rem;gap:.65rem">
                    <a href="{{ route('account.index') }}" class="btn btn-secondary">Annuler</a>
                    <button type="submit" id="upload-submit" class="btn btn-primary">
                        <i data-lucide="search-check" id="submit-icon" style="width:17px;height:17px"></i>
                        <span id="submit-label">Lancer l'analyse</span>
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

        {{-- Page de garde (optionnelle) : à choisir à l'étape Aperçu/Export --}}
        <section class="card" style="margin-top:1.5rem">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                <i data-lucide="book-open" style="width:19px;height:19px;color:var(--color-primary)"></i>
                <h2 class="card-title">Page de garde (page de couverture)</h2>
            </div>
            <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1.1rem">
                Ajoutez une couverture institutionnelle à votre rapport (facultatif).
                Vous pourrez l'appliquer à l'étape <strong>Aperçu / Export</strong>.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem">
                <a href="{{ route('cover-templates.from-example') }}" class="card" style="padding:1rem;text-decoration:none;display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--color-border)">
                    <span class="rc-icon"><i data-lucide="scan-text" style="width:17px;height:17px"></i></span>
                    <span>
                        <strong style="display:block;font-size:.88rem">Créer à partir d'un exemple</strong>
                        <small style="color:var(--color-text-muted);font-size:.78rem;display:block;margin-top:.2rem">Importez une couverture : les zones de texte sont détectées puis remplacées par vos informations.</small>
                    </span>
                </a>
                <a href="{{ route('cover-templates.create') }}" class="card" style="padding:1rem;text-decoration:none;display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--color-border)">
                    <span class="rc-icon"><i data-lucide="plus" style="width:17px;height:17px"></i></span>
                    <span>
                        <strong style="display:block;font-size:.88rem">Créer une page de garde</strong>
                        <small style="color:var(--color-text-muted);font-size:.78rem;display:block;margin-top:.2rem">Construisez un modèle avec le builder visuel (blocs, logos, placeholders).</small>
                    </span>
                </a>
                <a href="{{ route('cover-templates.index') }}" class="card" style="padding:1rem;text-decoration:none;display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--color-border)">
                    <span class="rc-icon"><i data-lucide="copy" style="width:17px;height:17px"></i></span>
                    <span>
                        <strong style="display:block;font-size:.88rem">Utiliser une page de garde existante</strong>
                        <small style="color:var(--color-text-muted);font-size:.78rem;display:block;margin-top:.2rem">Réutilisez ou dupliquez un modèle déjà enregistré.</small>
                    </span>
                </a>
            </div>
        </section>

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
            const dropzone = document.querySelector('.dropzone');
            const MAX_SIZE = 50 * 1024 * 1024; // 50 Mo
            const ALLOWED_EXT = ['docx', 'doc', 'txt'];

            // ── Aperçu du fichier sélectionné ──────────────────────────────────
            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' o';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
                return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
            }

            function validateFile(file) {
                const name = (file && file.name) || '';
                const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
                if (!ALLOWED_EXT.includes(ext)) {
                    return 'Format non pris en charge. Formats acceptés : .docx, .doc, .txt.';
                }
                if (file.size > MAX_SIZE) {
                    return 'Fichier trop volumineux (50 Mo maximum).';
                }
                return '';
            }

            function showPreview(file) {
                if (!file) return;

                const err = validateFile(file);
                if (err) {
                    preview.classList.add('hidden');
                    alert(err);
                    input.value = '';
                    return;
                }

                previewName.textContent = file.name;
                previewMeta.textContent = formatSize(file.size) + ' · ' + (file.type || 'inconnu');

                // Icône par type (P2 audit UI/UX : différencier visuellement)
                const lower = file.name.toLowerCase();
                let iconName = 'file-text';
                if (lower.endsWith('.docx')) {
                    iconName = 'file-text';      // Word récent
                } else if (lower.endsWith('.doc')) {
                    iconName = 'file-type';      // Word ancien
                } else if (lower.endsWith('.txt')) {
                    iconName = 'file-code-2';    // Texte brut
                }
                previewIcon.innerHTML = '<i data-lucide="' + iconName + '"></i>';
                if (window.lucide) lucide.createIcons();

                // Aperçu du contenu pour les .txt (petits fichiers uniquement)
                if (lower.endsWith('.txt') && file.size < 100000) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        previewText.textContent = e.target.result.slice(0, 4000);
                        previewContent.classList.remove('hidden');
                    };
                    reader.readAsText(file);
                } else {
                    previewContent.classList.add('hidden');
                }

                // Message d'aide selon le type (P2 audit UI/UX)
                const hint = document.getElementById('file-preview-hint');
                if (hint) {
                    if (lower.endsWith('.txt') && file.size >= 100000) {
                        hint.textContent = 'Aperçu disponible uniquement pour les fichiers .txt de moins de 100 Ko.';
                        hint.classList.remove('hidden');
                    } else if (lower.endsWith('.docx') || lower.endsWith('.doc')) {
                        hint.textContent = 'Aperçu du contenu disponible uniquement pour les fichiers .txt.';
                        hint.classList.remove('hidden');
                    } else {
                        hint.classList.add('hidden');
                    }
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

            // ── Drag & drop réel (la promesse « glissez-déposez » doit fonctionner) ──
            if (dropzone) {
                ['dragenter', 'dragover'].forEach(evt => {
                    dropzone.addEventListener(evt, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.add('drag-over');
                    });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    dropzone.addEventListener(evt, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.remove('drag-over');
                    });
                });
                dropzone.addEventListener('drop', (e) => {
                    const files = e.dataTransfer && e.dataTransfer.files;
                    if (files && files.length > 0) {
                        const dt = new DataTransfer();
                        dt.items.add(files[0]);
                        input.files = dt.files;
                        showPreview(files[0]);
                    }
                });
            }

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

                // Validation client avant envoi (taille + type)
                const err = validateFile(file);
                if (err) {
                    e.preventDefault();
                    alert(err);
                    return;
                }

                // Affiche l'animation + désactive le formulaire
                e.preventDefault();
                const submitBtn = document.getElementById('upload-submit');
                if (submitBtn) { submitBtn.disabled = true; submitBtn.setAttribute('aria-busy', 'true'); }
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
                }).catch(function () {
                    anim.finish();
                    appendLog('Erreur réseau : vérifiez votre connexion puis réessayez.', 'error');
                    titleEl.textContent = 'Analyse impossible';
                    subtitleEl.textContent = 'Veuillez vérifier votre connexion et réessayer.';
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
