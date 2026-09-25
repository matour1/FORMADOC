@extends('layouts.admin')

@section('title', 'Analyse')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Analyse</h1>
        <p>
            Évolution de l'activité, de la consommation IA et de la rentabilité.
            Fenêtre affichée : <strong>{{ $jours }} derniers jours</strong>.
            Les cumuls, eux, portent sur toute l'histoire du service.
        </p>
    </div>

    {{-- Sélecteur de fenêtre --}}
    <div class="tabs" style="margin-bottom:1.5rem">
        @foreach ($fenetres as $fenetre)
            <a class="tab {{ $jours === $fenetre ? 'active' : '' }}"
               href="{{ route('admin.analytics', ['jours' => $fenetre]) }}"
               @if ($jours === $fenetre) aria-current="page" @endif>
                {{ $fenetre }} jours
            </a>
        @endforeach
    </div>

    {{--
        Cumuls d'abord : ils répondent à « où en est-on » avant les courbes, et ils
        sont les seuls chiffres qui restent vrais quelle que soit la fenêtre. Une
        série à zéro sur 30 jours avec 1 500 FCFA encaissés au total n'est pas une
        anomalie, c'est un encaissement plus ancien que la fenêtre.
    --}}
    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Encaissé (total)</span>
            <span class="stat-value tabular">{{ number_format($cumuls['encaisse_total_fcfa'], 0, ',', ' ') }}</span>
            <span class="stat-hint">
                FCFA · {{ $cumuls['factures_total'] }} facture(s) payée(s)
            </span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Coût IA (total)</span>
            <span class="stat-value tabular">{{ number_format($cumuls['cout_ia_total_credits'], 0, ',', ' ') }}</span>
            <span class="stat-hint">
                crédits · {{ number_format($cumuls['appels_total'], 0, ',', ' ') }} appels
            </span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Crédits en circulation</span>
            <span class="stat-value tabular">{{ number_format($cumuls['credits_en_circulation'], 0, ',', ' ') }}</span>
            <span class="stat-hint">
                engagés sur {{ number_format($cumuls['utilisateurs'], 0, ',', ' ') }} compte(s)
            </span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Documents</span>
            <span class="stat-value tabular">{{ number_format($cumuls['documents'], 0, ',', ' ') }}</span>
            <span class="stat-hint">
                dont {{ $cumuls['echecs_total'] }} appel(s) IA en échec
            </span>
        </div>
    </div>

    {{-- ==================== Consommation IA ==================== --}}
    @php $maxCredits = max(1, ...$coutIa['series']['credits']); @endphp

    <section class="card" style="margin-bottom:1.5rem">
        <h2 class="card-title" style="margin-bottom:.3rem">Consommation IA par jour</h2>
        <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
            Crédits facturés par le fournisseur. Les échecs sont inclus : ils sont payés
            sans rien produire, donc leur part doit rester visible.
        </p>

        @if ($coutIa['vide'])
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                Aucun appel IA enregistré sur cette période. Ce n'est pas une chute d'activité :
                le registre est vide, donc aucune donnée n'existe pour la dessiner.
            </p>
        @else
            <x-chart-bars
                :labels="$coutIa['labels']"
                :series="['credits' => $coutIa['series']['credits'], 'echecs' => $coutIa['series']['echecs']]"
                :couleurs="['credits' => '', 'echecs' => 'accent']"
                :formats="['credits' => 'credit', 'echecs' => 'entier']"
                :legende="'Barre claire : crédits facturés. Barre accentuée : échecs (facturés eux aussi). Maximum de la période : '.number_format($maxCredits, 0, ',', ' ').' crédit(s) par jour.'"
            />
        @endif
    </section>

    {{-- ==================== Activité ==================== --}}
    <div class="split-grid" style="margin-bottom:1.5rem">
        @php $maxInscr = max(1, ...$inscriptions['series']['inscriptions']); @endphp

        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">Inscriptions</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Comptes créés par jour.
            </p>

            @if ($inscriptions['vide'])
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                    Aucune inscription sur cette période.
                </p>
            @else
                <x-chart-bars
                    :labels="$inscriptions['labels']"
                    :series="['inscriptions' => $inscriptions['series']['inscriptions']]"
                    :couleurs="['inscriptions' => 'accent']"
                    :hauteur="140"
                    :legende="array_sum($inscriptions['series']['inscriptions']).' inscription(s) sur la période.'"
                />
            @endif
        </section>

        @php $maxDocs = max(1, ...$documents['series']['total']); @endphp

        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">Documents déposés</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Volume traité par jour.
            </p>

            @if ($documents['vide'])
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                    Aucun document déposé sur cette période.
                </p>
            @else
                <x-chart-bars
                    :labels="$documents['labels']"
                    :series="['total' => $documents['series']['total'], 'echecs' => $documents['series']['echecs']]"
                    :couleurs="['total' => '', 'echecs' => 'accent']"
                    :hauteur="140"
                    :legende="array_sum($documents['series']['total']).' document(s) sur la période.'"
                />
            @endif
        </section>
    </div>

    {{-- ==================== Fournisseurs ==================== --}}
    <section class="card" style="margin-bottom:1.5rem">
        <h2 class="card-title" style="margin-bottom:.3rem">Consommation par fournisseur et modèle</h2>
        <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
            Quel modèle porte le coût, et lequel échoue. Un taux d'échec élevé sur un
            modèle payant est une fuite : chaque tentative est facturée sans produire de
            résultat.
        </p>

        @if ($fournisseurs === [])
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                Aucun appel IA enregistré : le registre d'usage est vide.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th>Modèle</th>
                            <th>Appels</th>
                            <th>Échecs</th>
                            <th>Tokens</th>
                            <th>Crédits</th>
                            <th>Part du coût</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fournisseurs as $ligne)
                            <tr>
                                <td data-label="Fournisseur" style="font-size:.78rem">{{ $ligne['provider'] }}</td>
                                <td data-label="Modèle" class="mono" style="font-size:.72rem">{{ $ligne['model'] }}</td>
                                <td data-label="Appels" class="num" style="font-size:.78rem">
                                    {{ number_format($ligne['appels'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Échecs" class="num" style="font-size:.78rem">
                                    @if ($ligne['echecs'] > 0)
                                        <span style="color:{{ $ligne['taux_echec'] >= 50 ? 'var(--color-danger)' : 'var(--color-warning)' }}">
                                            {{ $ligne['echecs'] }} ({{ $ligne['taux_echec'] }} %)
                                        </span>
                                    @else
                                        <span style="color:var(--color-text-muted)">0</span>
                                    @endif
                                </td>
                                <td data-label="Tokens" class="num" style="font-size:.78rem">
                                    {{ number_format($ligne['tokens'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Crédits" class="num" style="font-size:.78rem">
                                    {{ number_format($ligne['credits'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Part du coût">
                                    <div style="display:flex;align-items:center;gap:.5rem">
                                        <div class="progress" style="width:60px;flex-shrink:0">
                                            <div class="progress-bar" style="width:{{ $ligne['part'] }}%"></div>
                                        </div>
                                        <span style="font-size:.75rem;color:var(--color-text-muted)">{{ $ligne['part'] }} %</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ==================== Types de tâche ==================== --}}
    <div class="split-grid" style="margin-bottom:1.5rem">
        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">Répartition par type de tâche</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Ce que l'IA est réellement sollicitée pour faire. Oriente les décisions de
                tarification par usage.
            </p>

            @if ($taches === [])
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">Registre vide.</p>
            @else
                <div style="display:flex;flex-direction:column;gap:.85rem">
                    @foreach ($taches as $tache)
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                                <span>{{ $tache['task_type'] }}</span>
                                <span class="mono" style="color:var(--color-text-muted)">
                                    {{ number_format($tache['credits'], 0, ',', ' ') }} crédits ·
                                    {{ $tache['appels'] }} appels
                                    @if ($tache['echecs'] > 0)
                                        · {{ $tache['echecs'] }} échec(s)
                                    @endif
                                </span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width:{{ $tache['part'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ==================== Rentabilité ==================== --}}
        <section class="card">
            <h2 class="card-title" style="margin-bottom:.3rem">Rentabilité de la période</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Recettes encaissées contre coût réel de l'IA.
            </p>

            <div style="display:flex;flex-direction:column;gap:.7rem;font-size:.85rem">
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Encaissé</span>
                    <span class="tabular">{{ number_format($rentabilite['encaisse_fcfa'], 0, ',', ' ') }} FCFA</span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Coût IA</span>
                    <span class="tabular">{{ number_format($rentabilite['cout_ia_credits'], 0, ',', ' ') }} crédits</span>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px solid var(--color-border);padding-top:.7rem;font-weight:600">
                    <span>Écart</span>
                    <span class="tabular" style="color:{{ $rentabilite['ecart_credits'] >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }}">
                        {{ $rentabilite['ecart_credits'] > 0 ? '+' : '' }}{{ number_format($rentabilite['ecart_credits'], 0, ',', ' ') }} crédits
                    </span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Taux d'échec IA</span>
                    <span class="tabular">{{ $rentabilite['taux_echec'] }} %</span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--color-text-muted)">Dont échecs coûtant</span>
                    <span class="tabular">{{ number_format($rentabilite['cout_echecs_credits'], 0, ',', ' ') }} crédits</span>
                </div>
            </div>

            {{-- Un écart négatif n'est pas une perte : c'est un décalage entre la
                 période d'encaissement et la période de consommation. Le dire évite
                 une lecture alarmiste erronée. --}}
            @if ($rentabilite['ecart_credits'] < 0)
                <p class="form-hint" style="margin-top:1rem">
                    Écart négatif sur cette période : les appels IA consommés dépassent les
                    encaissements reçus. Cela arrive quand un achat de crédits est antérieur à
                    la fenêtre — les crédits achetés sont ensuite consommés pendant qu'aucun
                    nouveau paiement n'entre.
                </p>
            @endif

            <p class="form-hint" style="margin-top:1rem">
                Ce n'est pas une marge comptable : les frais de la passerelle, les
                remboursements et la fiscalité n'y sont pas déduits.
            </p>
        </section>
    </div>
@endsection
