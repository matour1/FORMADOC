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
        $images = $data['images'] ?? [];
        $titreCount = count($titres) + count($sousTitres);
        $isValidated = $document->status === 'validated';
    @endphp

    <div class="max-w-4xl">

        {{-- En-tête de page --}}
        <div class="page-header">
            <div>
                <span class="eyebrow">Étape 2 / 4 — Validation</span>
                <h1 style="word-break:break-word">{{ $document->filename }}</h1>
                <p>
                    {{ number_format(($document->metadata['size'] ?? 0) / 1024, 1) }} Ko
                    · {{ $document->metadata['mime_type'] ?? 'type inconnu' }}
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

            {{-- Couverture personnalisée (Phase 3, optionnel) --}}
            <section class="card" style="margin-bottom:1.25rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                    <i data-lucide="layout-template" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">Couverture personnalisée (optionnel)</h2>
                </div>
                <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                    Fournissez une couverture d'exemple (<code style="font-family:var(--font-mono);font-size:.78rem">.docx</code>) :
                    FORMADOC détecte les zones (nom, titre, encadrant, date) et les remplace en
                    conservant la structure et les styles.
                </p>
                <form method="POST" action="{{ route('documents.generate-cover', $document) }}" enctype="multipart/form-data">
                    @csrf
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                        <div style="grid-column:1 / -1">
                            <label for="cover" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Couverture d'exemple (.docx)</label>
                            <input type="file" class="form-control @error('cover') is-invalid @enderror" id="cover" name="cover" accept=".docx" required>
                            @error('cover')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>
                        <div>
                            <label for="nom" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Nom</label>
                            <input type="text" class="form-control" id="nom" name="nom" placeholder="Ex : JEAN DUPONT">
                        </div>
                        <div>
                            <label for="titre" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Titre</label>
                            <input type="text" class="form-control" id="titre" name="titre" placeholder="Ex : CONCEPTION D'UNE APPLICATION WEB">
                        </div>
                        <div>
                            <label for="encadrant" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Encadrant</label>
                            <input type="text" class="form-control" id="encadrant" name="encadrant" placeholder="Ex : Dr. MARTIN">
                        </div>
                        <div>
                            <label for="date" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--color-text-secondary)">Date / Année académique</label>
                            <input type="text" class="form-control" id="date" name="date" placeholder="Ex : 2025-2026">
                        </div>
                        <div style="grid-column:1 / -1;display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-secondary">
                                <i data-lucide="layout-template" style="width:16px;height:16px"></i>
                                Générer avec couverture
                            </button>
                        </div>
                    </div>
                </form>
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
