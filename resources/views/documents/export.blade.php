@extends('layouts.app')

@section('title', 'Résultat et téléchargement')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 4, 'document' => $document])

    @php
        // Deux formats coexistent (stratégie du strangler) :
        //  - `structural_json` : pipeline natif, la source de vérité dès qu'il existe ;
        //  - `structure` : format historique, pour les documents traités avant.
        //
        // **Pourquoi cette page devait lire AUSSI le pipeline natif.** Elle ne lisait
        // que `structure`, et le DOCX reconstruit est produit par le pipeline natif
        // (`DocumentController::writeDocument`). L'écran annonçait donc des chiffres
        // SANS RAPPORT avec le fichier téléchargé : mesuré sur un document réel,
        // « 8 titres, 0 légende » à l'export contre « 14 titres, 4 légendes » à la
        // validation — le même document, deux comptages. L'utilisateur qui vérifie
        // ce qu'il a obtenu lisait un résumé faux.
        $data = $structure->structure ?? [];
        $structurel = $structure?->structuralDocument();

        if ($structurel !== null) {
            $titres = array_map(
                static fn ($bloc): array => ['texte' => $bloc->text],
                $structurel->headings(),
            );
            $sousTitres = [];
            $titreCount = count($titres);
            $tableaux = $structurel->blocksOfType(\App\Document\Structure\BlockType::Table);
            $images = $structurel->blocksOfType(\App\Document\Structure\BlockType::Image);
            $legends = $structurel->blocksOfType(\App\Document\Structure\BlockType::Caption);

            // **En-têtes et pieds de page : un ÉTAT, pas un comptage.**
            // Le pipeline natif ne crée pas de bloc `header` : il signale leur
            // présence dans `meta.has_headers` (un en-tête est INJECTÉ depuis le
            // gabarit, il n'est pas recopié du document source). Compter
            // `blocksOfType(Header)` affichait « 0 » sur un document qui en a un —
            // en branchant cette page sur le natif, ce compteur est passé de 1 à 0,
            // d'un chiffre faux à un autre. On expose donc le FAIT (« présent » ou
            // non), jamais un nombre que le pipeline ne mesure pas.
            $aEnTete = $structurel->meta['has_headers'] ?? null;
            $aPied = $structurel->meta['has_footers'] ?? null;
        } else {
            $titres = $data['titres'] ?? [];
            $sousTitres = $data['sous_titres'] ?? [];
            $enTetes = $data['en_tetes'] ?? [];
            $piedsDePage = $data['pieds_de_page'] ?? [];
            $tableaux = $data['tableaux'] ?? [];
            $images = $data['images'] ?? [];
            $legends = $data['legends'] ?? [];
            $titreCount = count($titres) + count($sousTitres);
            // Format historique : les en-têtes/pieds sont de vrais textes, l'état
            // se déduit donc simplement de leur nombre.
            $aEnTete = count($enTetes) > 0;
            $aPied = count($piedsDePage) > 0;
        }

        $selectedTemplateId = $document->metadata['preview_template_id'] ?? null;
        $pdfAvailable = is_string($document->metadata['pdf_preview_path'] ?? null)
            && trim($document->metadata['pdf_preview_path'] ?? '') !== '';
        // Assistance IA : activée à l'analyse ? La structure a-t-elle été
        // corrigée par l'IA (ai_corrections non vide) ? Déjà régénérée sans IA ?
        $usedAi = (bool) ($document->metadata['use_ai'] ?? false)
            || !empty($data['ai_corrections']);
        $regeneratedWithoutAi = (bool) ($document->metadata['regenerated_without_ai'] ?? false);
    @endphp

    <div class="max-w-6xl">

        {{-- En-tête --}}
        <div class="page-header">
            <div>
                <span class="eyebrow">Étape 4 / 4 — Export</span>
                <h1>Votre document est prêt</h1>
                <p>
                    <strong>{{ $document->filename }}</strong> a été mis en forme. Téléchargez le
                    DOCX reconstruit ci-dessous.
                </p>
            </div>
        </div>

        <div class="grid export-bento-grid">

            {{-- Colonne principale : récapitulatif (bento) --}}
            <div style="display:flex;flex-direction:column;gap:1.4rem;min-width:0"
                 x-data="formatExport({{ $selectedTemplateId ?: 'null' }})">

                {{-- Rapport de vérification de conformité académique (Volet 3) --}}
                @if (! empty($complianceReport))
                    @php
                        $compStatus = $complianceReport['status'] ?? 'partiel';
                    @endphp
                    <section class="card compliance-card is-{{ $compStatus }}">
                        <div class="compliance-head">
                            <div>
                                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem">
                                    <i data-lucide="shield-check" style="width:19px;height:19px;color:var(--color-primary)"></i>
                                    <h2 class="card-title">Vérification de conformité académique</h2>
                                </div>
                                <p style="color:var(--color-text-secondary);font-size:.84rem;margin:0">
                                    Contrôle du respect de la structure de référence (Sections 1 & 2, en-tête/pied, Times New Roman 12 pt justifié, Table des matières).
                                </p>
                            </div>
                            <div class="compliance-score-badge">
                                <div>
                                    <span class="compliance-score-num">{{ $complianceReport['score'] }}%</span>
                                    <span style="font-size:.72rem;color:var(--color-text-muted);display:block">
                                        {{ $complianceReport['passed_count'] }}/{{ $complianceReport['total_count'] }} critères
                                    </span>
                                </div>
                                <span class="badge {{ $compStatus === 'conforme' ? 'badge-success' : ($compStatus === 'partiel' ? 'badge-warning' : 'badge-danger') }}">
                                    {{ $complianceReport['status_label'] }}
                                </span>
                            </div>
                        </div>

                        <div class="compliance-cat-grid">
                            @foreach ($complianceReport['categories'] ?? [] as $cat)
                                <div class="compliance-cat-box">
                                    <div class="compliance-cat-title">
                                        <span>{{ $cat['label'] }}</span>
                                        <span class="mono" style="font-size:.72rem;color:var(--color-text-muted)">
                                            {{ $cat['passed'] }}/{{ $cat['total'] }}
                                        </span>
                                    </div>
                                    <ul class="compliance-check-list">
                                        @foreach ($cat['items'] as $item)
                                            <li class="compliance-check-item">
                                                <span class="compliance-check-icon {{ $item['passed'] ? 'ok' : 'warn' }}">
                                                    {{ $item['passed'] ? '✓' : '!' }}
                                                </span>
                                                <div style="min-width:0">
                                                    <div style="font-weight:600;color:var(--color-text)">{{ $item['label'] }}</div>
                                                    <div style="font-size:.73rem;color:var(--color-text-muted)">{{ $item['detail'] }}</div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Rappel & Ajustement rapide de l'En-tête et du Pied de page (Volet 1) --}}
                @php
                    $hf = $headerFooterConfig ?? [
                        'theme' => $document->metadata['theme'] ?? '',
                        'redacteur' => $document->metadata['redacteur'] ?? '',
                        'mention_redacteur' => $document->metadata['mention_redacteur'] ?? '',
                        'effective_header' => $document->metadata['theme'] ?? '',
                        'effective_footer' => $document->metadata['mention_redacteur'] ?? '',
                    ];
                @endphp
                <section class="card hf-config-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.6rem">
                        <div style="display:flex;align-items:center;gap:.5rem">
                            <i data-lucide="panel-top" style="width:19px;height:19px;color:var(--color-primary)"></i>
                            <h2 class="card-title">Contenu de l'en-tête et du pied de page appliqués</h2>
                        </div>
                        <span class="badge badge-info">Romain (i, ii…) → Arabe (1, 2…)</span>
                    </div>
                    <form method="POST" action="{{ route('documents.header-footer.update', $document) }}">
                        @csrf
                        <div class="form-grid" style="margin-bottom:.85rem">
                            <div class="form-group" style="margin-bottom:0">
                                <label for="export_hf_theme">En-tête (Thème du rapport)</label>
                                <input type="text" id="export_hf_theme" name="theme" class="form-control" maxlength="300"
                                       value="{{ old('theme', $hf['theme'] ?: $hf['effective_header']) }}"
                                       placeholder="Thème du rapport…">
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label for="export_hf_mention">Pied de page (Mention du rédacteur)</label>
                                <input type="text" id="export_hf_mention" name="mention_redacteur" class="form-control" maxlength="300"
                                       value="{{ old('mention_redacteur', $hf['mention_redacteur'] ?: $hf['effective_footer']) }}"
                                       placeholder="RÉDIGÉ EN VUE D'ÊTRE PRÉSENTÉ PAR : …">
                                <input type="hidden" name="redacteur" value="{{ $hf['redacteur'] }}">
                            </div>
                        </div>
                        <div style="display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i data-lucide="save" style="width:14px;height:14px"></i>
                                Mettre à jour l'en-tête / pied de page
                            </button>
                        </div>
                    </form>
                </section>

                {{-- Page de garde : FONCTIONNALITÉ NON DISPONIBLE dans cette version.
                     Le formulaire de sélection de modèle a été supprimé en même
                     temps que ses routes. Un contrôle inerte serait plus trompeur
                     qu'un message clair : l'utilisateur croirait pouvoir s'en servir.
                     L'objectif de la version est la MISE EN FORME du document. --}}
                <section class="card">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                        <i data-lucide="info" style="width:19px;height:19px;color:var(--color-text-muted)"></i>
                        <h2 class="card-title">Page de garde — fonctionnalité non disponible</h2>
                    </div>
                    <p style="color:var(--color-text-secondary);font-size:.85rem;margin:0">
                        La génération de page de garde n'est <strong>pas disponible</strong> dans cette
                        version. L'attention porte sur la <strong>mise en forme</strong> : titres,
                        styles, numérotation des figures et tableaux, sommaire et listes.
                    </p>
                </section>

                {{-- Mise en forme : choix du gabarit + aperçu PDF --}}
                <section class="card">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                        <i data-lucide="palette" style="width:19px;height:19px;color:var(--color-primary)"></i>
                        <h2 class="card-title">Mise en forme du rapport</h2>
                    </div>
                    <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1.1rem">
                        Choisissez un gabarit : il s'applique à l'ensemble du rapport
                        (titres, corps du texte, tableaux, images, marges, interligne…).
                        Changez de gabarit et relancez l'aperçu : aucune ré-importation nécessaire.
                    </p>

                    <form method="POST" action="{{ route('documents.preview-pdf', $document) }}"
                          style="display:flex;flex-direction:column;gap:1rem">
                        @csrf

                        <div>
                            <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Gabarit de mise en forme</label>
                            <select name="template_id" x-model="templateId" class="form-control">
                                <option value="">— Mise en forme par défaut —</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected($template->id === $selectedTemplateId)>
                                        {{ $template->name }} — {{ $template->description }}
                                    </option>
                                @endforeach
                            </select>
                            @error('template_id')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div style="display:flex;flex-wrap:wrap;gap:.6rem">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="eye" style="width:16px;height:16px"></i>
                                Générer l'aperçu PDF
                            </button>

                            <button type="submit" form="download-form" class="btn btn-secondary">
                                <i data-lucide="download" style="width:16px;height:16px"></i>
                                Télécharger le DOCX
                            </button>
                        </div>
                    </form>

                    {{-- Aperçu PDF (iframe) --}}
                    @if ($pdfAvailable)
                        <div style="margin-top:1.4rem">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                                <p class="mono" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted)">Aperçu fidèle du document</p>
                                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.75rem;color:var(--color-text-secondary)">
                                    <i data-lucide="file-text" style="width:14px;height:14px"></i>
                                    PDF généré par LibreOffice
                                </span>
                            </div>
                            <div style="background:var(--color-surface-2);border-radius:var(--radius-sm);padding:.5rem;border:1px solid var(--color-border)">
                                <iframe src="{{ route('documents.preview-pdf.file', $document) }}"
                                        style="width:100%;height:640px;border-radius:var(--radius-sm);background:#fff" title="Aperçu du document"></iframe>
                            </div>
                        </div>
                    @else
                        <div style="margin-top:1.4rem;background:var(--color-surface-2);border-radius:var(--radius-sm);padding:1.4rem;border:1px dashed var(--color-border);text-align:center">
                            <i data-lucide="file-text" style="width:28px;height:28px;color:var(--color-primary)"></i>
                            <p style="font-size:.88rem;color:var(--color-text-secondary);margin-top:.6rem">
                                Aucun aperçu généré pour l'instant. Cliquez sur
                                « Générer l'aperçu PDF » pour voir le rendu exact avant de télécharger.
                            </p>
                        </div>
                    @endif
                </section>

                    {{-- Comparaison avec/sans IA (exigence Phase 4) --}}
                    <section class="card">
                        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                            <i data-lucide="git-compare" style="width:19px;height:19px;color:var(--color-primary)"></i>
                            <h2 class="card-title">Assistance IA</h2>
                        </div>

                        @if ($usedAi)
                            <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                                Ce document a été analysé avec l'assistance IA
                                @if (!empty($data['ai_corrections']))
                                    ({{ count($data['ai_corrections']) }} correction(s) appliquée(s) : listes, niveaux, ambiguïtés).
                                @else
                                    (aucune correction n'a été nécessaire).
                                @endif
                                Vous pouvez régénérer la structure en mode 100 % déterministe
                                (aucun appel externe) pour comparer les deux rendus.
                            </p>

                            @if ($regeneratedWithoutAi)
                                <div class="banner">
                                    <i data-lucide="badge-check"></i>
                                    <p style="font-size:.85rem">
                                        La structure actuelle a été <strong>régénérée sans IA</strong>.
                                        L'aperçu PDF ci-dessus reflète le rendu déterministe.
                                    </p>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('documents.preview-pdf', $document) }}">
                                @csrf
                                <input type="hidden" name="regenerate_without_ai" value="1">
                                <button type="submit" class="btn btn-secondary">
                                    <i data-lucide="rotate-ccw" style="width:16px;height:16px"></i>
                                    {{ $regeneratedWithoutAi ? 'Regénérer l\'aperçu sans IA' : 'Régénérer sans IA pour comparer' }}
                                </button>
                            </form>
                        @else
                            <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                                Ce document a été analysé en mode 100 % déterministe :
                                aucun appel à un service d'IA externe n'a été émis.
                            </p>
                            <div class="banner">
                                <i data-lucide="shield-check" style="width:16px;height:16px"></i>
                                <p style="font-size:.85rem">
                                    La détection (titres, listes, tableaux, images) repose uniquement
                                    sur les styles Word, les motifs regex et les règles déterministes.
                                </p>
                            </div>
                        @endif
                    </section>

                    {{-- Carte de téléchargement --}}
                    <div style="background:var(--color-primary);color:#fff;border-radius:var(--radius);padding:1.6rem 1.8rem;display:flex;flex-direction:column;gap:1.2rem;align-items:flex-start">
                        <div>
                            <p style="font-size:1.25rem;font-weight:700;margin-bottom:.2rem">DOCX reconstruit</p>
                            <p style="font-size:.85rem;opacity:.9">
                                Styles natifs de titres, sommaire, en-têtes, pieds de page et légendes.
                            </p>
                        </div>
                        <form method="POST" action="{{ route('documents.generate', $document) }}" id="download-form">
                            @csrf
                            <input type="hidden" name="template_id" :value="templateId" x-ref="downloadTemplateId">
                            <button type="submit" class="btn" style="background:#fff;color:var(--color-primary)">
                                <i data-lucide="download" style="width:16px;height:16px"></i>
                                Télécharger le DOCX
                            </button>
                        </form>
                    </div>

                    {{-- Bento : statistiques de la structure --}}
                    <div class="export-stats-grid">
                        @php
                            $stats = [
                                ['git-branch', 'Titres', $titreCount],
                                ['table', 'Tableaux', count($tableaux)],
                                ['image', 'Images', count($images)],
                                ['tag', 'Légendes', count($legends)],
                                ['panel-top', 'En-tête', $aEnTete === null ? '—' : ($aEnTete ? 'Oui' : 'Non')],
                                ['panel-bottom', 'Pied de page', $aPied === null ? '—' : ($aPied ? 'Oui' : 'Non')],
                            ];
                        @endphp
                        @foreach ($stats as $stat)
                            <div class="card" style="padding:1rem">
                                <i data-lucide="{{ $stat[0] }}" style="width:18px;height:18px;color:var(--color-primary)"></i>
                                <p style="font-size:1.25rem;font-weight:700;margin-top:.4rem">{{ $stat[2] }}</p>
                                <p style="font-size:.78rem;color:var(--color-text-muted)">{{ $stat[1] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Rapport utilisateur structuré (§9, D-F1) --}}
                    <x-document-user-report :report="$userReport ?? null" />

                    {{-- Aperçu du plan --}}
                    @if ($titreCount > 0)
                        <section class="card">
                            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                                <i data-lucide="list-ordered" style="width:19px;height:19px;color:var(--color-primary)"></i>
                                <h2 class="card-title">Plan du document</h2>
                            </div>
                            <div style="background:var(--color-surface-2);border-radius:var(--radius-sm);padding:1.1rem 1.3rem;max-height:24rem;overflow-y:auto">
                                @foreach ($titres as $titre)
                                    <h3 style="font-weight:700;margin-bottom:.5rem">{{ $titre['texte'] ?? '' }}</h3>
                                @endforeach
                                @foreach ($sousTitres as $sousTitre)
                                    <h4 style="font-weight:600;color:var(--color-text-secondary);margin-bottom:.5rem;margin-left:1.5rem">{{ $sousTitre['texte'] ?? '' }}</h4>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    {{-- Actions --}}
                    <div style="display:flex;flex-wrap:wrap;gap:.6rem">
                        <a href="{{ route('documents.create') }}" class="btn btn-ghost">
                            <i data-lucide="plus" style="width:16px;height:16px"></i>
                            Nouveau rapport
                        </a>
                        <a href="{{ route('feedback.form') }}" class="btn btn-ghost">
                            <i data-lucide="heart" style="width:16px;height:16px"></i>
                            Votre avis
                        </a>
                    </div>
                </div>

                {{-- Colonne latérale : résumé des actions --}}
                <aside style="display:flex;flex-direction:column;gap:1.4rem;min-width:0">
                    <section class="card">
                        <h2 class="card-title" style="margin-bottom:1rem">Résumé des actions</h2>
                        <ul style="display:flex;flex-direction:column;gap:.8rem;list-style:none">
                            <li style="display:flex;align-items:center;gap:.7rem;font-size:.88rem">
                                <i data-lucide="file-up" style="width:16px;height:16px;color:var(--color-primary)"></i>
                                Upload du rapport
                            </li>
                            <li style="display:flex;align-items:center;gap:.7rem;font-size:.88rem">
                                <i data-lucide="clipboard-check" style="width:16px;height:16px;color:var(--color-primary)"></i>
                                Validation de la structure
                            </li>
                            <li style="display:flex;align-items:center;gap:.7rem;font-size:.88rem">
                                <i data-lucide="wand-2" style="width:16px;height:16px;color:var(--color-primary)"></i>
                                Mise en forme automatique
                            </li>
                            <li style="display:flex;align-items:center;gap:.7rem;font-size:.88rem">
                                <i data-lucide="badge-check" style="width:16px;height:16px;color:var(--color-primary)"></i>
                                {{ $titreCount }} titres mis en forme
                            </li>
                            <li style="display:flex;align-items:center;gap:.7rem;font-size:.88rem">
                                <i data-lucide="list" style="width:16px;height:16px;color:var(--color-primary)"></i>
                                Sommaire {{ $titreCount > 0 ? 'généré' : 'non généré (aucun titre)' }}
                            </li>
                        </ul>
                    </section>

                    <div class="card" style="background:var(--color-surface-2);border-color:transparent">
                        <p class="mono" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.5rem">Votre expérience</p>
                        <p style="font-size:.85rem;color:var(--color-text-secondary);margin-bottom:.8rem">
                            Aidez-nous à améliorer FORMADOC en partageant votre avis.
                        </p>
                        <a href="{{ route('feedback.form') }}" class="btn btn-ghost btn-sm">
                            <i data-lucide="heart" style="width:15px;height:15px"></i>
                            Donner mon avis
                        </a>
                    </div>
                </aside>

            </div>

        </div>
@endsection

@push('scripts')
<script>
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