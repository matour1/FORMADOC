@extends('layouts.admin')

@section('title', 'Surveillance')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Surveillance du traitement</h1>
        <p>
            Ce qui bloque maintenant : documents arrêtés à une étape, passages en attente de
            décision, pipelines réellement exercés.
        </p>
    </div>

    {{--
        Les seuils sont annoncés explicitement. Un code couleur sans légende se
        découvre par tâtonnement, et une alerte incomprise est une alerte ignorée —
        y compris la suivante, qui serait légitime.
    --}}
    <div class="banner" style="margin-bottom:1.5rem">
        <i data-lucide="info"></i>
        <div style="font-size:.85rem">
            Seuils appliqués : <strong>{{ $seuilAttention }} jours</strong> sans mouvement →
            à vérifier · <strong>{{ $seuilAbandon }} jours</strong> → probablement abandonné ·
            <strong>{{ $seuilPassages }} passages</strong> à confirmer sur un même document →
            classification en échec.
            <div style="margin-top:.35rem;color:var(--color-text-secondary)">
                Ces seuils distinguent un défaut technique d'un utilisateur qui a simplement
                abandonné — un document ancien n'est pas nécessairement bloqué.
            </div>
        </div>
    </div>

    {{-- ==================== Points d'attention ==================== --}}
    <h2 style="font-size:1rem;margin-bottom:.9rem">Points d'attention</h2>

    @if ($points === [])
        <div class="card" style="margin-bottom:1.75rem">
            <p style="margin:0;font-size:.9rem;color:var(--color-text-secondary)">
                Aucun point d'attention : aucun document bloqué, aucun passage excessif, aucun
                échec de tâche.
            </p>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.75rem">
            @foreach ($points as $point)
                <div class="banner {{ $point['niveau'] === 'danger' ? 'banner-danger' : ($point['niveau'] === 'warning' ? 'banner-warning' : '') }}">
                    <i data-lucide="{{ $point['niveau'] === 'danger' ? 'alert-circle' : ($point['niveau'] === 'warning' ? 'alert-triangle' : 'info') }}"></i>
                    <div>
                        <strong>{{ $point['titre'] }}</strong>
                        <div style="font-size:.85rem;margin-top:.25rem;opacity:.9">
                            {{ $point['detail'] }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ==================== Chiffres clés ==================== --}}
    @php
        $totalDocuments = array_sum(array_column($statuts, 'total'));
        $totalAbandonnes = array_sum(array_column($statuts, 'abandonnes'));
        $totalAttention = array_sum(array_column($statuts, 'attention'));
    @endphp

    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Documents</span>
            <span class="stat-value tabular">{{ number_format($totalDocuments, 0, ',', ' ') }}</span>
            <span class="stat-hint">tous statuts confondus</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Inachevés</span>
            <span class="stat-value tabular">{{ number_format($totalAttention, 0, ',', ' ') }}</span>
            <span class="stat-hint">sans mouvement depuis {{ $seuilAttention }} j ou plus</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Passages à confirmer</span>
            <span class="stat-value tabular">{{ number_format($clarifications['total'], 0, ',', ' ') }}</span>
            <span class="stat-hint">sur {{ $clarifications['documents'] }} document(s)</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Pipeline natif</span>
            <span class="stat-value tabular">{{ $pipelines['part_natif'] }} %</span>
            <span class="stat-hint">
                {{ $pipelines['natif'] }} sur {{ $pipelines['total'] }} structure(s)
            </span>
        </div>
    </div>

    {{-- ==================== Documents par statut ==================== --}}
    <section class="card" style="margin-bottom:1.5rem">
        <h2 class="card-title" style="margin-bottom:.3rem">Répartition par étape</h2>
        <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
            L'âge se compte depuis le dernier mouvement, pas depuis le dépôt : un document
            récemment déposé et jamais touché n'a pas la même signification qu'un ancien oublié.
        </p>

        @if ($statuts === [])
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">Aucun document en base.</p>
        @else
            @php
                $libelles = [
                    'pending' => 'En attente',
                    'processing' => 'En traitement',
                    'detected' => 'Structure détectée',
                    'validated' => 'Validé',
                    'generated' => 'Généré',
                    'ready' => 'Prêt',
                    'failed' => 'Échec',
                ];
            @endphp
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Étape</th>
                            <th>Documents</th>
                            <th>Âge moyen</th>
                            <th>Le plus ancien</th>
                            <th>À vérifier</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($statuts as $statut)
                            <tr>
                                <td data-label="Étape" style="font-size:.82rem">
                                    {{ $libelles[$statut['status']] ?? $statut['status'] }}
                                </td>
                                <td data-label="Documents" class="num" style="font-size:.82rem">
                                    {{ number_format($statut['total'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Âge moyen" class="num" style="font-size:.82rem">
                                    {{ number_format($statut['age_moyen'], 1, ',', ' ') }} j
                                </td>
                                <td data-label="Le plus ancien" class="num" style="font-size:.82rem">
                                    {{ $statut['age_max'] }} j
                                </td>
                                <td data-label="À vérifier" style="font-size:.82rem">
                                    @if ($statut['abandonnes'] > 0)
                                        <span class="badge">{{ $statut['abandonnes'] }} abandonné(s)</span>
                                    @endif
                                    @if ($statut['attention'] - $statut['abandonnes'] > 0)
                                        <span class="badge badge-warning">
                                            {{ $statut['attention'] - $statut['abandonnes'] }} à examiner
                                        </span>
                                    @endif
                                    @if ($statut['attention'] === 0)
                                        <span style="color:var(--color-text-muted)">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ==================== Documents bloqués ==================== --}}
    <section class="card" style="margin-bottom:1.5rem">
        <h2 class="card-title" style="margin-bottom:.3rem">Documents les plus anciens non terminés</h2>
        <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
            Les documents terminés sont exclus : un document prêt n'est pas « bloqué » parce
            qu'il est ancien.
        </p>

        @if ($bloques->isEmpty())
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                Aucun document en attente de traitement.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fichier</th>
                            <th>Étape</th>
                            <th>Âge</th>
                            <th>Déposé le</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bloques as $document)
                            <tr>
                                <td data-label="Fichier" style="font-size:.8rem">
                                    {{ $document->filename }}
                                    <div class="mono" style="font-size:.68rem;color:var(--color-text-muted)">
                                        #{{ $document->id }}
                                    </div>
                                </td>
                                <td data-label="Étape" style="font-size:.78rem">
                                    {{ $libelles[$document->status] ?? $document->status }}
                                </td>
                                <td data-label="Âge" class="num" style="font-size:.78rem">
                                    <span style="color:{{ $document->age_jours >= $seuilAbandon ? 'var(--color-text-muted)' : ($document->age_jours >= $seuilAttention ? 'var(--color-warning)' : 'inherit') }}">
                                        {{ $document->age_jours }} j
                                    </span>
                                </td>
                                <td data-label="Déposé le" style="font-size:.78rem">
                                    {{ \Illuminate\Support\Carbon::parse($document->created_at)->format('d/m/Y') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ==================== Pipelines et file ==================== --}}
    <div class="split-grid">
        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">Pipeline réellement exercé</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                La question à laquelle un écran de santé doit répondre : le nouveau pipeline
                tourne-t-il vraiment en production ?
            </p>

            @if ($pipelines['total'] === 0)
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                    Aucune structure en base : aucun document n'a encore été analysé.
                </p>
            @else
                <div style="display:flex;flex-direction:column;gap:.85rem">
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                            <span>Natif (refonte)</span>
                            <span class="mono" style="color:var(--color-text-muted)">{{ $pipelines['natif'] }}</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width:{{ $pipelines['part_natif'] }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                            <span>Historique (PHPWord)</span>
                            <span class="mono" style="color:var(--color-text-muted)">{{ $pipelines['historique'] }}</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width:{{ 100 - $pipelines['part_natif'] }}%;background:var(--color-border)"></div>
                        </div>
                    </div>
                </div>

                @if ($pipelines['natif'] === 0)
                    <p class="form-hint" style="margin-top:1rem">
                        Aucune structure ne porte la marque du pipeline natif. Si la configuration est
                        en « auto », le repli sur l'ancien chemin est systématique : tout fonctionne,
                        mais rien de ce qui a été développé en R1→R7 n'est exercé.
                    </p>
                @endif
            @endif
        </section>

        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">File d'attente</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Le traitement est synchrone : ces tables devraient rester vides.
            </p>

            <div style="display:flex;flex-direction:column;gap:.7rem;font-size:.85rem">
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Tâches en attente</span>
                    <span class="tabular">{{ number_format($file['en_attente'], 0, ',', ' ') }}</span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Tâches en échec</span>
                    <span class="tabular" style="color:{{ $file['echoues'] > 0 ? 'var(--color-danger)' : 'inherit' }}">
                        {{ number_format($file['echoues'], 0, ',', ' ') }}
                    </span>
                </div>
                @if ($file['plus_ancien_echec'] !== null)
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--color-text-muted)">Plus ancien échec</span>
                        <span>{{ $file['plus_ancien_echec'] }}</span>
                    </div>
                @endif
            </div>

            <p class="form-hint" style="margin-top:1rem">
                Les surveiller n'est pas décoratif : le jour où une étape passerait en asynchrone,
                un échec s'y logerait sans que rien ne le signale ailleurs.
            </p>
        </section>
    </div>

    {{-- ==================== Clarifications détaillées ==================== --}}
    @if ($clarifications['excessive'] !== [])
        <section class="card" style="margin-top:1.5rem">
            <h2 class="card-title" style="margin-bottom:.3rem">Documents à classification défaillante</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Ces documents se sont terminés sans erreur, mais demandent trop de confirmations
                manuelles pour être exploitables : la classification a échoué en pratique.
            </p>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Document</th><th>Passages à confirmer</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($clarifications['excessive'] as $cas)
                            <tr>
                                <td data-label="Document" class="mono" style="font-size:.8rem">
                                    #{{ $cas['document_id'] }}
                                </td>
                                <td data-label="Passages" class="num" style="font-size:.82rem">
                                    <span style="color:var(--color-warning)">
                                        {{ number_format($cas['passages'], 0, ',', ' ') }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('admin.documents', ['status' => 'validated']) }}"
                                       style="font-size:.8rem">Voir les documents</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
