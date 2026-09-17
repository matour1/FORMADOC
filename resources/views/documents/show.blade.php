@extends('layouts.app')

@section('title', 'Validation de la structure')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 2, 'document' => $document])

    @php
        // Deux formats coexistent (stratégie du strangler) :
        //  - `structural_json` : produit par le nouveau pipeline (R1-R2), la
        //    source de vérité dès qu'elle existe ;
        //  - `structure` : format historique, conservé pour les documents traités
        //    avant l'activation du pipeline.
        //
        // Afficher `structure` alors que le document a une structure native
        // montrerait « 0 titre » sur un document dont la classification en a
        // détecté des dizaines : l'écran mentirait sur ce que contient le fichier.
        //
        // Les deux sources sont NORMALISÉES vers un format d'affichage commun :
        // sinon chaque section devrait connaître les deux formes, et la moindre
        // divergence produirait des cases vides.
        $structurel = $structure?->structuralDocument();

        $normaliserLegende = static fn (array $legende): array => [
            'line' => $legende['line'] ?? '',
            'type' => $legende['type'] ?? '',
            'number' => $legende['number'] ?? '',
            'label' => $legende['label'] ?? ($legende['texte'] ?? ''),
        ];

        if ($structurel !== null) {
            $pipelineNatif = true;

            $titres = array_map(
                static fn ($bloc): array => [
                    'texte' => $bloc->text,
                    'niveau' => $bloc->headingLevel ?? 1,
                    'numero' => $bloc->displayNumber(),
                ],
                $structurel->headings(),
            );
            $sousTitres = [];
            $titreCount = count($titres);

            $tableaux = array_map(
                static fn ($bloc): array => [
                    'rows_count' => $bloc->tableData?->rows,
                    'texte' => $bloc->text,
                ],
                $structurel->blocksOfType(\App\Document\Structure\BlockType::Table),
            );

            $images = array_map(
                static fn ($bloc): array => [
                    'image_name' => $bloc->imageRef ?? 'image',
                    'texte' => $bloc->text,
                ],
                $structurel->blocksOfType(\App\Document\Structure\BlockType::Figure),
            );

            // Légendes : le libellé est le texte sans le préfixe « Figure 3 : »,
            // pour ne pas répéter le numéro déjà affiché dans sa colonne.
            $legends = array_map(
                static fn ($bloc): array => [
                    'line' => $bloc->positionY ?? '',
                    'type' => $bloc->category?->listTitle() ?? 'Légende',
                    'number' => $bloc->displayNumber() ?? '',
                    'label' => trim((string) preg_replace('/^[^:]{0,40}:\s*/u', '', $bloc->text)),
                ],
                $structurel->blocksOfType(\App\Document\Structure\BlockType::Caption),
            );

            $ambiguitesStructurelles = $structurel->ambiguous();
        } else {
            $pipelineNatif = false;
            $data = $structure->structure ?? [];

            $titres = $data['titres'] ?? [];
            $sousTitres = $data['sous_titres'] ?? [];
            $titreCount = count($titres) + count($sousTitres);
            $tableaux = $data['tableaux'] ?? [];
            $images = $data['images'] ?? [];
            $legends = array_map($normaliserLegende, $data['legends'] ?? []);
            $ambiguitesStructurelles = [];
        }

        $ambiguities = $structure->ambiguities ?? [];
        $enTetes = $data['en_tetes'] ?? [];
        $piedsDePage = $data['pieds_de_page'] ?? [];
        $isValidated = $document->status === 'validated';
    @endphp

    <div class="max-w-4xl">

        {{-- En-tête de page --}}
        <div class="page-header">
            <div>
                <span class="eyebrow">Étape 2 / 4 — Vérifier la structure</span>
                <h1 style="word-break:break-word">{{ $document->filename }}</h1>
                <p>
                    {{ number_format(($document->metadata['size'] ?? 0) / 1024, 1) }} Ko
                    · {{ $document->metadata['mime_type'] ?? 'type inconnu' }}
                    @if ($pipelineNatif)
                        · <span title="Structure lue directement dans le fichier Word, sans modèle de langue">analyse native</span>
                    @endif
                </p>
            </div>
        </div>

        @if (! $structure)
            <div class="card">
                <p style="color:var(--color-text-secondary);font-size:.9rem">
                    La structure de ce document n'a pas encore été analysée.
                </p>
            </div>
        @else
            {{-- Bandeau d'état --}}
            <div class="banner {{ $isValidated ? 'banner-success' : '' }}">
                @if ($isValidated)
                    <i data-lucide="badge-check"></i>
                    <div>
                        <strong>Structure validée</strong> — prête pour le traitement.
                        <div style="margin-top:.6rem">
                            <a href="{{ route('documents.processing', $document) }}" class="btn btn-primary btn-sm">
                                Continuer vers le traitement <i data-lucide="arrow-right" style="width:15px;height:15px"></i>
                            </a>
                        </div>
                    </div>
                @else
                    <i data-lucide="clipboard-check"></i>
                    <div>Vérifiez la structure détectée puis lancez le traitement.</div>
                @endif
            </div>

            {{-- Annulation des modifications faites par le chat (R6 §9.7) --}}
            {{-- Affiché seulement s'il y a quelque chose à annuler : un bloc vide
                 laisserait croire à une fonctionnalité en panne. --}}
            @if (! empty($undoHistory))
                <section class="card" style="margin-bottom:1.25rem">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                        <i data-lucide="undo-2" style="width:19px;height:19px;color:var(--color-primary)"></i>
                        <h2 class="card-title">Annuler une modification</h2>
                    </div>
                    <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                        Chaque modification faite par le chat enregistre l'état précédent du document.
                        Restaurer un état <strong>remplace le contenu actuel</strong> — l'annulation
                        n'est pas elle-même annulable.
                    </p>

                    <div style="display:flex;flex-direction:column;gap:.6rem">
                        @foreach ($undoHistory as $action)
                            <form method="POST" action="{{ route('documents.undo-edit', $document) }}"
                                  style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                                @csrf
                                <input type="hidden" name="snapshot_id" value="{{ $action['id'] }}">
                                <div style="min-width:0">
                                    <div style="font-size:.88rem">{{ $action['reason'] }}</div>
                                    <div style="font-size:.78rem;color:var(--color-text-secondary)">
                                        {{ $action['block_count'] }} blocs
                                        · {{ \Illuminate\Support\Str::substr($action['created_at'] ?? '', 0, 16) }}
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    Restaurer cet état
                                </button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Réanalyse (3 modes : regex / IA / assistée) --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                    <i data-lucide="refresh-cw" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Réanalyse (comparer les méthodes)</h2>
                </div>
                <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                    Relancez l'analyse sur le fichier source avec une autre méthode
                    <strong>sans ré-uploader</strong>. La structure précédente est conservée
                    (traçabilité avant/après), puis remplacée par le nouveau résultat.
                </p>

                <form method="POST" action="{{ route('documents.reanalyze', $document) }}">
                    @csrf

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem" role="radiogroup" aria-label="Méthode de réanalyse">
                        <label class="radio-card" for="reanalyze-regex">
                            <input type="radio" name="title_method" id="reanalyze-regex" value="regex"
                                   @checked(($document->metadata['title_method'] ?? 'regex') !== 'ia')>
                            <span class="rc-icon"><i data-lucide="file-check" style="width:17px;height:17px"></i></span>
                            <span>
                                <strong>Analyse rapide (Regex)</strong>
                                <small>Déterministe et hors-ligne : styles Word (Heading, tailles, gras) + motifs regex. Aucune donnée envoyée à l'extérieur.</small>
                            </span>
                        </label>

                        <label class="radio-card" for="reanalyze-ia">
                            <input type="radio" name="title_method" id="reanalyze-ia" value="ia"
                                   @checked(($document->metadata['title_method'] ?? '') === 'ia')>
                            <span class="rc-icon"><i data-lucide="bot" style="width:17px;height:17px"></i></span>
                            <span>
                                <strong>Analyse assistée par IA</strong>
                                <small>Le même document est relu par un modèle de langue pour confirmer les passages ambigus. Plus lent et facturé en crédits.</small>
                            </span>
                        </label>
                    </div>

                    <label for="reanalyze-use-ai" class="check-card" style="margin-top:1.2rem">
                        <input type="checkbox" name="use_ai" id="reanalyze-use-ai" value="1"
                               @checked((bool) ($document->metadata['use_ai'] ?? false))>
                        <span class="cc-icon"><i data-lucide="sparkles" style="width:17px;height:17px"></i></span>
                        <span>
                            <strong>Confirmer les passages incertains par l'IA</strong>
                            <small>Quand la détection déterministe hésite, le modèle tranche. Sans cette option, ces passages vous sont présentés à confirmer — vous gardez la décision.</small>
                            <span class="cc-note">Consomme 1 unité du quota IA si activée.</span>
                        </span>
                    </label>

                    <div style="display:flex;justify-content:flex-end;margin-top:1.2rem">
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="refresh-cw" style="width:16px;height:16px"></i>
                            Relancer l'analyse
                        </button>
                    </div>
                </form>
            </section>

            {{-- Validation des ambiguïtés --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                    <i data-lucide="{{ count($ambiguities) > 0 ? 'alert-triangle' : 'badge-check' }}" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Validation des ambiguïtés</h2>
                </div>

                @if ($isValidated)
                    <p style="color:var(--color-text-secondary);font-size:.88rem">
                        Aucune ambiguïté restante : les corrections ont été appliquées au plan.
                    </p>
                @else
                    <form method="POST" action="{{ route('documents.validate', $document) }}">
                        @csrf

                        @if (count($ambiguities) > 0)
                            <p style="color:var(--color-text-secondary);font-size:.88rem;margin-bottom:1rem">
                                Certaines numérotations ne correspondent pas au niveau détecté.
                                Corrigez si nécessaire, puis validez.
                            </p>

                            <div style="display:flex;flex-direction:column;gap:.7rem;margin-bottom:1.4rem">
                                @foreach ($ambiguities as $ambiguity)
                                    <div style="display:flex;flex-direction:column;gap:.8rem;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:1rem;background:var(--color-surface-2)">
                                        <div style="flex:1;min-width:0">
                                            <p style="font-weight:600;font-size:.9rem;word-break:break-word">{{ $ambiguity['texte'] }}</p>
                                            <p style="color:var(--color-text-muted);font-size:.78rem;margin-top:.3rem">
                                                {{ $ambiguity['raison'] }}
                                                <span class="badge badge-info" style="margin-left:.3rem">
                                                    <i data-lucide="lightbulb" style="width:12px;height:12px"></i>
                                                    Suggéré : niveau {{ $ambiguity['niveau_suggere'] }}
                                                </span>
                                            </p>
                                        </div>
                                        <div style="max-width:240px">
                                            <label style="font-family:var(--font-mono);font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);display:block;margin-bottom:.3rem">
                                                Niveau {{ $ambiguity['niveau_detecte'] }} → correction
                                            </label>
                                            <select name="corrections[{{ $ambiguity['id'] }}]" class="form-control" style="font-size:.85rem">
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
                            <p style="color:var(--color-text-secondary);font-size:.88rem;margin-bottom:1rem">
                                Aucune ambiguïté détectée. La structure semble cohérente.
                            </p>
                        @endif

                        <div style="display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="rocket" style="width:16px;height:16px"></i>
                                Valider et lancer le traitement
                            </button>
                        </div>
                    </form>
                @endif

                {{-- Confirmation des blocs ambigus (R2).
                     Place ici parce que c'est le moment où l'utilisateur examine la
                     structure : lui proposer de confirmer les passages incertains
                     ailleurs dissocierait la question de son contexte. --}}
                @php $aConfirmer = $document->clarifications()->whereNull('answered_at')->count(); @endphp
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--color-border);display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                    <a href="{{ route('documents.clarifications.index', $document) }}"
                       class="btn {{ $aConfirmer > 0 ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                        <i data-lucide="circle-help" style="width:15px;height:15px"></i>
                        @if ($aConfirmer > 0)
                            Confirmer {{ $aConfirmer }} passage{{ $aConfirmer > 1 ? 's' : '' }}
                        @else
                            Passages à confirmer
                        @endif
                    </a>
                    <span style="font-size:.78rem;color:var(--color-text-muted)">
                        @if ($aConfirmer > 0)
                            Passages dont la nature est incertaine (titre, légende, tableau).
                        @else
                            Aucun passage en attente de confirmation.
                        @endif
                    </span>
                </div>
            </section>

            {{-- Hiérarchie des titres --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                    <i data-lucide="git-branch" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Hiérarchie des titres détectée</h2>
                </div>
                @if ($titreCount > 0)
                    <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.8rem">{{ $titreCount }} titres détectés</p>
                    <div style="background:var(--color-surface-2);border-radius:var(--radius-sm);padding:1.1rem 1.3rem">
                        @foreach ($titres as $titre)
                            <h3 style="font-weight:700;margin-bottom:.5rem">{{ $titre['texte'] ?? '' }}</h3>
                        @endforeach
                        @foreach ($sousTitres as $sousTitre)
                            <h4 style="font-weight:600;color:var(--color-text-secondary);margin-bottom:.5rem;margin-left:1.5rem">{{ $sousTitre['texte'] ?? '' }}</h4>
                        @endforeach
                    </div>
                @else
                    <p style="color:var(--color-text-secondary);font-size:.88rem">Aucun titre détecté dans ce document.</p>
                @endif
            </section>

            {{-- En-têtes et pieds de page --}}
            @if (count($enTetes) > 0 || count($piedsDePage) > 0)
                <section class="card" style="margin-bottom:1.25rem">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                        <i data-lucide="panel-top" style="width:19px;height:19px;color:var(--color-primary)"></i>
                        <h2 class="card-title">En-têtes et pieds de page</h2>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.4rem">
                        <div>
                            <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.5rem">En-têtes</p>
                            <ul style="display:flex;flex-direction:column;gap:.3rem;list-style:none">
                                @forelse ($enTetes as $enTete)
                                    <li style="font-size:.88rem">{{ $enTete['texte'] ?? '' }}</li>
                                @empty
                                    <li style="font-size:.85rem;color:var(--color-text-muted)">Aucun en-tête.</li>
                                @endforelse
                            </ul>
                        </div>
                        <div>
                            <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.5rem">Pieds de page</p>
                            <ul style="display:flex;flex-direction:column;gap:.3rem;list-style:none">
                                @forelse ($piedsDePage as $pied)
                                    <li style="font-size:.88rem">{{ $pied['texte'] ?? '' }}</li>
                                @empty
                                    <li style="font-size:.85rem;color:var(--color-text-muted)">Aucun pied de page.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Légendes --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                    <i data-lucide="tag" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Légendes détectées (figures, tableaux, annexes…)</h2>
                </div>
                @if (count($legends) > 0)
                    <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.8rem">{{ count($legends) }} légendes détectées</p>
                    <div class="table-wrap">
                        <table class="data-table">
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
                                        <td style="color:var(--color-text-muted)" data-label="Ligne">{{ $legend['line'] }}</td>
                                        <td data-label="Type">{{ $legend['type'] }}</td>
                                        <td data-label="N°">{{ $legend['number'] }}</td>
                                        <td data-label="Libellé">{{ $legend['label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p style="color:var(--color-text-secondary);font-size:.88rem">Aucune légende détectée.</p>
                @endif
            </section>

            {{-- Images et tableaux détectés --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                    <i data-lucide="image" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Images et tableaux détectés</h2>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.4rem">
                    <div>
                        <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.5rem">
                            Images ({{ count($images) }})
                        </p>
                        @if (count($images) > 0)
                            <ul style="display:flex;flex-direction:column;gap:.45rem;list-style:none">
                                @foreach ($images as $image)
                                    <li style="display:flex;align-items:center;gap:.55rem;font-size:.88rem">
                                        <i data-lucide="image" style="width:15px;height:15px;color:var(--color-text-muted);flex-shrink:0"></i>
                                        <span style="word-break:break-all">{{ $image['image_name'] ?? ($image['texte'] ?? 'image') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p style="font-size:.85rem;color:var(--color-text-muted)">Aucune image détectée.</p>
                        @endif
                    </div>
                    <div>
                        <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.5rem">
                            Tableaux ({{ count($tableaux) }})
                        </p>
                        @if (count($tableaux) > 0)
                            <ul style="display:flex;flex-direction:column;gap:.45rem;list-style:none">
                                @foreach ($tableaux as $tableau)
                                    <li style="display:flex;align-items:center;gap:.55rem;font-size:.88rem">
                                        <i data-lucide="table" style="width:15px;height:15px;color:var(--color-text-muted);flex-shrink:0"></i>
                                        <span>
                                            Tableau
                                            @if (isset($tableau['rows_count']))
                                                · {{ $tableau['rows_count'] }} ligne(s)
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p style="font-size:.85rem;color:var(--color-text-muted)">Aucun tableau détecté.</p>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Page de garde : RETIRÉE de cette version du produit.
                 Ce n'est pas un oubli mais une décision de périmètre : l'objectif
                 de la version est la MISE EN FORME du document (titres, styles,
                 numérotation, sommaire, listes). Laisser un formulaire inerte
                 serait pire que l'absence — l'utilisateur croirait pouvoir
                 l'utiliser. On l'explique donc explicitement. --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                    <i data-lucide="info" style="width:19px;height:19px;color:var(--color-text-muted)"></i>
                    <h2 class="card-title">Page de garde</h2>
                </div>
                <p style="color:var(--color-text-secondary);font-size:.85rem;margin:0">
                    La génération de page de garde n'est pas disponible dans cette version.
                    FORMADOC se concentre sur la <strong>mise en forme</strong> de votre document :
                    titres, styles, numérotation des figures et tableaux, sommaire et listes.
                </p>
            </section>
        @endif

        {{-- Actions secondaires --}}
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.4rem">
            <a href="{{ route('documents.create') }}" class="btn btn-ghost">
                <i data-lucide="plus" style="width:16px;height:16px"></i>
                Analyser un autre rapport
            </a>
            <a href="{{ route('feedback.form') }}" class="btn btn-ghost">
                <i data-lucide="heart" style="width:16px;height:16px"></i>
                Donner mon avis
            </a>
        </div>

    </div>
@endsection
