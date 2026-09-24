@extends('layouts.admin')

@section('title', 'Qualité de la classification')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Qualité</span>
        <h1>Qualité de la classification</h1>
        <p>
            Ce que la détection a su trancher, et ce qui lui a résisté. Les documents les plus
            incertains sont en tête : ce sont eux qui demandent une action.
        </p>
    </div>

    @php
        // Le taux de confiance est l'indicateur qui décide d'ajuster le seuil de
        // 0,85 : trop bas, l'utilisateur confirme trop de passages ; trop haut, des
        // erreurs de détection passent inaperçues.
        $tauxMoyen = $lignes === []
            ? null
            : round(array_sum(array_column($lignes, 'taux')) / count($lignes), 1);
    @endphp

    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Documents analysés</span>
            <span class="stat-value tabular">{{ count($lignes) }}</span>
            <span class="stat-hint">100 derniers au maximum</span>
        </div>

        {{-- Le taux de confiance moyen n'est calculé QUE s'il y a des lignes.
             La version précédente affichait « — » dans ce cas : un tiret occupe
             la place d'une mesure et laisse croire qu'elle a été tentée. Une
             donnée absente s'omet, elle ne se représente pas. --}}
        @if ($tauxMoyen !== null)
            <div class="stat-item">
                <span class="stat-label">Taux de confiance moyen</span>
                <span class="stat-value tabular">{{ $tauxMoyen }} %</span>
                <span class="stat-hint">seuil d'acceptation : {{ \App\Document\Structure\Block::AUTO_ACCEPT_THRESHOLD * 100 }} %</span>
            </div>
        @endif

        <div class="stat-item">
            <span class="stat-label">Blocs ambigus</span>
            <span class="stat-value tabular">{{ $totalAmbigus }}</span>
            <span class="stat-hint">titres non tranchés</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Documents sans titre</span>
            <span class="stat-value tabular">{{ $sansTitre }}</span>
            <span class="stat-hint">pas de sommaire possible</span>
        </div>
    </div>

    <section class="card">
        @if ($lignes === [])
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                Aucun document analysé par le pipeline natif. Activez
                <code>DOCUMENT_PIPELINE_V2</code> pour que la classification s'exécute.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Blocs</th>
                            <th>Titres</th>
                            <th>Ambigus</th>
                            <th>Confiance</th>
                            <th>À confirmer</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lignes as $ligne)
                            <tr>
                                <td data-label="Document" style="font-size:.82rem;word-break:break-word">
                                    {{ \Illuminate\Support\Str::limit($ligne['document']->filename, 46) }}
                                    @if ($ligne['sans_titre'])
                                        <span style="font-size:.7rem;color:var(--color-danger)" title="Aucun titre détecté : ni sommaire ni hiérarchie possibles">
                                            sans titre
                                        </span>
                                    @endif
                                </td>
                                <td data-label="Blocs">{{ $ligne['blocks'] }}</td>
                                <td data-label="Titres">{{ $ligne['titres'] }}</td>
                                <td data-label="Ambigus">
                                    {{ $ligne['ambigus'] }}
                                    @if ($ligne['ambigus'] > 0)
                                        <span style="font-size:.72rem;color:var(--color-text-muted)">
                                            ({{ round($ligne['ambigus'] / $ligne['blocks'] * 100) }} %)
                                        </span>
                                    @endif
                                </td>
                                <td data-label="Confiance">
                                    {{-- Repère visuel : sous 85 %, une part notable demande confirmation --}}
                                    <span style="color:{{ $ligne['taux'] >= 85 ? 'var(--color-success)' : ($ligne['taux'] >= 60 ? 'inherit' : 'var(--color-danger)') }}">
                                        {{ $ligne['taux'] }} %
                                    </span>
                                </td>
                                <td data-label="À confirmer">
                                    @if ($ligne['en_attente'] > 0)
                                        <a href="{{ route('documents.clarifications.index', $ligne['document']) }}"
                                           style="font-size:.8rem">{{ $ligne['en_attente'] }} passage(s)</a>
                                    @else
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

    <div class="card" style="background:var(--color-surface-2);border-color:transparent;margin-top:1.5rem">
        <p style="font-size:.82rem;color:var(--color-text-secondary);margin:0">
            <strong>Comment lire le taux de confiance.</strong> Il mesure la part de blocs classés
            sans hésitation (au-dessus du seuil). Un taux bas n'est pas forcément un défaut : un
            document sans style Word ni numérotation en produit naturellement. Un taux qui reste bas
            après activation de l'assistance IA signale en revanche un problème de détection.
        </p>
    </div>
@endsection
