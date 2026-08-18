@extends('layouts.app')

@section('title', 'Résultat et téléchargement')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 4, 'document' => $document])

    @php
        $data = $structure->structure ?? [];
        $titres = $data['titres'] ?? [];
        $sousTitres = $data['sous_titres'] ?? [];
        $enTetes = $data['en_tetes'] ?? [];
        $piedsDePage = $data['pieds_de_page'] ?? [];
        $tableaux = $data['tableaux'] ?? [];
        $images = $data['images'] ?? [];
        $legends = $data['legends'] ?? [];
        $titreCount = count($titres) + count($sousTitres);
        $selectedTemplateId = $document->metadata['preview_template_id'] ?? null;
        $pdfAvailable = is_string($document->metadata['pdf_preview_path'] ?? null)
            && trim($document->metadata['pdf_preview_path'] ?? '') !== '';
    @endphp

    <div class="md:ml-64">
        <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

            {{-- En-tête --}}
            <div class="mb-8 md:mb-12">
                <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Étape 4 / 4 — Export</p>
                <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                    Votre document est prêt
                </h1>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    <strong>{{ $document->filename }}</strong> a été mis en forme. Téléchargez le
                    DOCX reconstruit ci-dessous.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Colonne principale : récapitulatif (bento) --}}
                <div class="lg:col-span-2 space-y-6"
                     x-data="formatExport({{ $selectedTemplateId ?: 'null' }})">

                    {{-- Page de garde : sélection d'un modèle visuel (facultatif) --}}
                    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-primary">auto_stories</span>
                            <h2 class="font-h2 text-h2 text-on-surface">Ajouter une page de garde</h2>
                        </div>
                        <p class="font-caption text-caption text-on-surface-variant mb-4">
                            Choisissez un modèle visuel de couverture (facultatif). Les champs
                            <code class="font-mono text-[12px] bg-surface-container-high px-1 rounded">&lcub;&lcub;placeholder&rcub;&rcub;</code>
                            du modèle seront pré-remplis ci-dessous.
                        </p>

                        <form method="POST" action="{{ route('documents.generate-cover-page', $document) }}"
                              class="space-y-4" x-data="coverExport({{ \Illuminate\Support\Js::from($coverTemplates) }})">
                            @csrf

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Modèle de page de garde</label>
                                <select name="cover_page_template_id" x-model="selectedId"
                                        class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-3 text-body-md text-on-surface focus:border-primary focus:outline-none">
                                    <option value="">— Aucune page de garde —</option>
                                    <template x-for="t in templates" :key="t.id">
                                        <option :value="t.id" x-text="t.name"></option>
                                    </template>
                                </select>
                                @error('cover_page_template_id')
                                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Champs des placeholders du modèle sélectionné --}}
                            <template x-if="selected">
                                <div>
                                    <p class="text-sm font-medium text-slate-700 mb-2">
                                        Valeurs pour « <span x-text="selected.name"></span> »
                                    </p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <template x-for="key in placeholderKeys(selected)" :key="key">
                                            <div>
                                                <label class="block text-xs font-medium text-on-surface-variant mb-1"
                                                       x-text="key"></label>
                                                <input type="text" :name="`values[${key}]`"
                                                       class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-body-md text-on-surface focus:border-primary focus:outline-none"
                                                       :placeholder="ph(key)">
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex justify-end mt-4">
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 bg-primary text-on-primary hover:bg-primary-fixed px-5 py-2.5 rounded-xl font-body-md font-semibold transition-colors">
                                            <span class="material-symbols-outlined">auto_awesome</span>
                                            Télécharger avec page de garde
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </form>
                    </div>

                    {{-- Mise en forme : choix du gabarit + aperçu PDF --}}
                    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-primary">palette</span>
                            <h2 class="font-h2 text-h2 text-on-surface">Mise en forme du rapport</h2>
                        </div>
                        <p class="font-caption text-caption text-on-surface-variant mb-4">
                            Choisissez un gabarit : il s'applique à l'ensemble du rapport
                            (titres, corps du texte, tableaux, images, marges, interligne…).
                            Changez de gabarit et relancez l'aperçu : aucune ré-importation nécessaire.
                        </p>

                        <form method="POST" action="{{ route('documents.preview-pdf', $document) }}"
                              class="space-y-4">
                            @csrf

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Gabarit de mise en forme</label>
                                <select name="template_id" x-model="templateId"
                                        class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-3 text-body-md text-on-surface focus:border-primary focus:outline-none">
                                    <option value="">— Mise en forme par défaut —</option>
                                    @foreach ($templates as $template)
                                        <option value="{{ $template->id }}" @selected($template->id === $selectedTemplateId)>
                                            {{ $template->name }} — {{ $template->description }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('template_id')
                                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-primary text-on-primary hover:bg-primary-fixed px-5 py-2.5 rounded-xl font-body-md font-semibold transition-colors">
                                    <span class="material-symbols-outlined">visibility</span>
                                    Générer l'aperçu PDF
                                </button>

                                <button type="submit" form="download-form"
                                        class="inline-flex items-center gap-2 bg-on-primary-container text-primary hover:bg-surface-container-high px-5 py-2.5 rounded-xl font-body-md font-semibold transition-colors">
                                    <span class="material-symbols-outlined">download</span>
                                    Télécharger le DOCX
                                </button>
                            </div>
                        </form>

                        {{-- Aperçu PDF (iframe) --}}
                        @if ($pdfAvailable)
                            <div class="mt-6">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="font-label-mono text-label-mono text-secondary uppercase">Aperçu fidèle du document</p>
                                    <span class="inline-flex items-center gap-1 font-caption text-caption text-secondary">
                                        <span class="material-symbols-outlined text-[14px]">picture_as_pdf</span>
                                        PDF généré par LibreOffice
                                    </span>
                                </div>
                                <div class="bg-surface-container-low rounded-xl p-2 border border-outline-variant">
                                    <iframe src="{{ route('documents.preview-pdf.file', $document) }}"
                                            class="w-full h-[640px] rounded-lg bg-white" title="Aperçu du document"></iframe>
                                </div>
                            </div>
                        @else
                            <div class="mt-6 bg-surface-container-low rounded-xl p-5 border border-dashed border-outline-variant text-center">
                                <span class="material-symbols-outlined text-primary text-3xl">picture_as_pdf</span>
                                <p class="font-body-md text-body-md text-on-surface-variant mt-2">
                                    Aucun aperçu généré pour l'instant. Cliquez sur
                                    « Générer l'aperçu PDF » pour voir le rendu exact avant de télécharger.
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Carte de téléchargement --}}
                    <div class="bg-primary text-on-primary rounded-xl p-6 md:p-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <p class="font-h2 text-h2 mb-1">DOCX reconstruit</p>
                            <p class="font-caption text-caption opacity-90">
                                Styles natifs de titres, sommaire, en-têtes, pieds de page et légendes.
                            </p>
                        </div>
                        <form method="POST" action="{{ route('documents.generate', $document) }}" id="download-form">
                            @csrf
                            <input type="hidden" name="template_id" :value="templateId" x-ref="downloadTemplateId">
                            <button type="submit"
                                    class="inline-flex items-center gap-2 bg-on-primary text-primary hover:bg-primary-fixed px-6 py-3 rounded-xl font-body-md font-semibold transition-colors">
                                <span class="material-symbols-outlined">download</span>
                                Télécharger le DOCX
                            </button>
                        </form>
                    </div>

                    {{-- Bento : statistiques de la structure --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @php
                            $stats = [
                                ['account_tree', 'Titres', $titreCount],
                                ['grid_on', 'Tableaux', count($tableaux)],
                                ['image', 'Images', count($images)],
                                ['badge', 'Légendes', count($legends)],
                                ['vertical_align_top', 'En-têtes', count($enTetes)],
                                ['vertical_align_bottom', 'Pieds de page', count($piedsDePage)],
                            ];
                        @endphp
                        @foreach ($stats as $stat)
                            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4">
                                <span class="material-symbols-outlined text-primary">{{ $stat[0] }}</span>
                                <p class="font-h2 text-h2 text-on-surface mt-2">{{ $stat[2] }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $stat[1] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Aperçu du plan --}}
                    @if ($titreCount > 0)
                        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-6">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-primary">format_list_numbered</span>
                                <h2 class="font-h2 text-h2 text-on-surface">Plan du document</h2>
                            </div>
                            <div class="bg-surface-container-low rounded-xl p-5 font-doc-preview text-doc-preview max-h-96 overflow-y-auto">
                                @foreach ($titres as $titre)
                                    <h3 class="font-bold text-on-surface mb-2">{{ $titre['texte'] ?? '' }}</h3>
                                @endforeach
                                @foreach ($sousTitres as $sousTitre)
                                    <h4 class="font-semibold text-on-surface-variant mb-2 ml-6">{{ $sousTitre['texte'] ?? '' }}</h4>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('documents.create') }}"
                           class="inline-flex items-center gap-2 text-primary hover:bg-surface-container-high px-4 py-2 rounded-lg font-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined">add</span>
                            Nouveau rapport
                        </a>
                        <a href="{{ route('feedback.form') }}"
                           class="inline-flex items-center gap-2 text-secondary hover:bg-surface-container-high px-4 py-2 rounded-lg font-body-md transition-colors">
                            <span class="material-symbols-outlined">rate_review</span>
                            Votre avis
                        </a>
                    </div>
                </div>

                {{-- Colonne latérale : résumé des actions --}}
                <aside class="space-y-6">
                    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5">
                        <h2 class="font-h2 text-h2 text-on-surface mb-4">Résumé des actions</h2>
                        <ul class="space-y-3">
                            <li class="flex items-center gap-3 text-body-md">
                                <span class="material-symbols-outlined text-primary">upload_file</span>
                                Upload du rapport
                            </li>
                            <li class="flex items-center gap-3 text-body-md">
                                <span class="material-symbols-outlined text-primary">fact_check</span>
                                Validation de la structure
                            </li>
                            <li class="flex items-center gap-3 text-body-md">
                                <span class="material-symbols-outlined text-primary">auto_awesome</span>
                                Mise en forme automatique
                            </li>
                            <li class="flex items-center gap-3 text-body-md">
                                <span class="material-symbols-outlined text-primary">task_alt</span>
                                {{ $titreCount }} titres mis en forme
                            </li>
                            <li class="flex items-center gap-3 text-body-md">
                                <span class="material-symbols-outlined text-primary">format_list_bulleted</span>
                                Sommaire {{ $titreCount > 0 ? 'généré' : 'non généré (aucun titre)' }}
                            </li>
                        </ul>
                    </div>

                    <div class="bg-surface-container-low border border-outline-variant rounded-xl p-5">
                        <p class="font-label-mono text-label-mono text-secondary uppercase mb-2">Votre expérience</p>
                        <p class="font-caption text-caption text-on-surface-variant mb-3">
                            Aidez-nous à améliorer FORMADOC en partageant votre avis.
                        </p>
                        <a href="{{ route('feedback.form') }}"
                           class="inline-flex items-center gap-2 text-primary hover:bg-surface-container-high px-3 py-2 rounded-lg font-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined">favorite</span>
                            Donner mon avis
                        </a>
                    </div>
                </aside>

            </div>

        </div>
    </div>
@endsection

@push('scripts')
<script>
    function coverExport(templates) {
        return {
            templates: templates || [],
            selectedId: '',
            get selected() {
                return this.templates.find(t => String(t.id) === String(this.selectedId)) || null;
            },
            placeholderKeys(t) {
                if (!t || !t.elements) return [];
                const set = new Set();
                const walk = (blocks) => (blocks || []).forEach(b => {
                    if (b.kind === 'text' && b.text) {
                        (String(b.text).match(/\{\{\s*([\w\.]+)\s*\}\}/g) || []).forEach(m => {
                            set.add(m.replace(/[{}\s]/g, ''));
                        });
                    }
                });
                (t.elements || []).forEach(r => (r.cells || []).forEach(c => walk(c.blocks)));
                return [...set];
            },
            ph(key) {
                // Évite les accolades littérales (interprétées par Blade)
                return '{' + '{' + key + '}' + '}';
            },
        };
    }

    function formatExport(selectedId) {
        return {
            templateId: selectedId || '',
            init() {
                // Synchronise le champ caché du formulaire de téléchargement
                this.$watch('templateId', (v) => {
                    if (this.$refs.downloadTemplateId) {
                        this.$refs.downloadTemplateId.value = v;
                    }
                });
            },
        };
    }
</script>
@endpush