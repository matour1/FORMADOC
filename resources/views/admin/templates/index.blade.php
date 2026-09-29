@extends('layouts.admin')

@section('title', 'Gabarits de mise en forme')

{{--
    Liste des gabarits, avec leur usage réel.

    Le nombre de documents générés est affiché parce qu'il conditionne une
    décision : un gabarit employé ne se supprime pas comme un gabarit jamais
    utilisé. Sans ce chiffre, l'administrateur le découvrirait au moment où la
    suppression est refusée.
--}}

@section('content')
    <div class="page-head">
        <div>
            <h1>Gabarits de mise en forme</h1>
            <p>
                Un gabarit définit la police, les tailles, les couleurs, l'interligne et les marges
                appliqués aux documents. <strong>Modifier un gabarit change la mise en forme de tous
                les documents à venir</strong> — les documents déjà générés ne sont pas modifiés.
            </p>
        </div>
        <a href="{{ route('admin.templates.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:16px;height:16px"></i>
            Nouveau gabarit
        </a>
    </div>

    {{-- Convention des vues admin : le layout affiche déjà les ERREURS
         (« Action refusée »), chaque vue affiche son SUCCÈS. On suit la même
         règle ici, sans dupliquer le bandeau d'erreur du layout. --}}
    @if (session('success'))
        <div class="banner banner-success">
            <i data-lucide="check-circle" style="width:17px;height:17px"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Réglages principaux</th>
                        <th>Documents générés</th>
                        <th>Publication</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        @php
                            // Normalisation à l'affichage : un gabarit ancien peut
                            // ne pas avoir toutes les clés. Sans cela, la ligne
                            // afficherait des vides là où le moteur applique en
                            // réalité une valeur par défaut.
                            $p = \App\Services\DocumentGeneration\TemplateStyleResolver::normalize($template->params);
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $template->name }}</strong>
                                @if ($template->description)
                                    <div style="font-size:.76rem;color:var(--color-text-muted);margin-top:.15rem">
                                        {{ $template->description }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-size:.78rem;font-family:var(--font-mono)">
                                {{ $p['police'] }} · {{ $p['tailles']['corps'] }} pt ·
                                interligne {{ $p['interligne'] }} ·
                                <span title="Marge gauche, en twips (1440 = 2,54 cm)">{{ $p['marges']['left'] }} tw</span>
                            </td>
                            <td>
                                @if ($template->generated_documents_count > 0)
                                    <span class="badge badge-info">{{ $template->generated_documents_count }}</span>
                                @else
                                    <span style="color:var(--color-text-muted);font-size:.8rem">aucun</span>
                                @endif
                            </td>
                            <td>
                                @if ($template->is_public)
                                    <span class="badge badge-success">proposé</span>
                                @else
                                    <span class="badge">masqué</span>
                                @endif
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <a href="{{ route('admin.templates.edit', $template) }}" class="btn btn-secondary btn-sm">
                                    <i data-lucide="pencil" style="width:13px;height:13px"></i>
                                    Modifier
                                </a>

                                {{-- Publication : action distincte, car dépublier ne
                                     touche aucun réglage. --}}
                                <form method="POST" action="{{ route('admin.templates.publication', $template) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm"
                                            title="{{ $template->is_public ? 'Retirer des choix proposés' : 'Proposer aux utilisateurs' }}">
                                        <i data-lucide="{{ $template->is_public ? 'eye-off' : 'eye' }}" style="width:13px;height:13px"></i>
                                        {{ $template->is_public ? 'Masquer' : 'Publier' }}
                                    </button>
                                </form>

                                {{-- Suppression proposée seulement quand elle est possible.
                                     Afficher un bouton qui échoue systématiquement ferait
                                     perdre un aller-retour pour rien. --}}
                                @if ($template->generated_documents_count === 0)
                                    <form method="POST" action="{{ route('admin.templates.destroy', $template) }}"
                                          class="inline"
                                          onsubmit="return confirm('Supprimer définitivement le gabarit « {{ $template->name }} » ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-correction)">
                                            <i data-lucide="trash-2" style="width:13px;height:13px"></i>
                                            Supprimer
                                        </button>
                                    </form>
                                @else
                                    <span class="btn btn-ghost btn-sm"
                                          style="opacity:.45;cursor:not-allowed"
                                          title="Suppression impossible : {{ $template->generated_documents_count }} document(s) généré(s) avec ce gabarit. Masquez-le à la place.">
                                        <i data-lucide="lock" style="width:13px;height:13px"></i>
                                        Supprimer
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--color-text-muted);padding:2rem">
                                Aucun gabarit. Créez-en un pour qu'une mise en forme soit proposée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="banner banner-info" style="margin-top:1.5rem">
        <i data-lucide="info" style="width:17px;height:17px"></i>
        <div>
            <strong>Les unités affichées sont celles du moteur de génération.</strong>
            Les tailles sont en POINTS (12 = 12 pt, comme dans Word) : c'est ce que
            reçoit directement PhpWord. Les marges et espacements sont en twips
            (1440 = 2,54 cm, soit un pouce) — 240 vaut donc environ 4,2 mm.
            Le formulaire rappelle ces équivalences.
        </div>
    </div>
@endsection
