@extends('layouts.admin')

@section('title', 'Vue d\'ensemble')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Vue d'ensemble</h1>
        <p>État du service, coût réel de l'IA et points demandant une action.</p>
    </div>

    @php
        // Coût des seules tentatives échouées : ce qui est payé sans contrepartie
        // pour l'utilisateur. Un montant élevé signale une instabilité de
        // fournisseur, pas une erreur de facturation — mais il doit rester visible.
        $fuite = $coutTotal['credits'] > 0
            ? round($coutTotal['failures'] / max(1, $coutTotal['attempts']) * 100, 1)
            : 0;
    @endphp

    {{-- Chiffres clés.
         Le balisage utilise `.stat-grid` / `.stat-item`, déjà défini par le
         design system et employé par le tableau de bord utilisateur. La version
         précédente réécrivait les mêmes cartes en styles inline : taille de
         police, marges et graisse étaient figées à quatre endroits, donc un
         ajustement du design system ne les atteignait pas. --}}
    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Documents</span>
            <span class="stat-value">{{ number_format($totalDocuments, 0, ',', ' ') }}</span>
            <span class="stat-hint">{{ $avecStructureNative }} avec structure native</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Utilisateurs</span>
            <span class="stat-value">{{ number_format($totalUtilisateurs, 0, ',', ' ') }}</span>
            <span class="stat-hint">Comptes créés</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Coût IA réel</span>
            <span class="stat-value tabular">{{ number_format($coutTotal['credits'], 0, ',', ' ') }}</span>
            <span class="stat-hint">crédits · {{ number_format($coutTotal['usd'], 4, ',', ' ') }} USD · {{ $coutTotal['attempts'] }} tentatives</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Échecs facturés</span>
            <span class="stat-value tabular">{{ $coutTotal['failures'] }}</span>
            <span class="stat-hint">
                {{ $fuite }} % des tentatives
                @if ($coutTotal['fallbacks'] > 0)
                    · {{ $coutTotal['fallbacks'] }} bascule(s)
                @endif
            </span>
        </div>
    </div>

    {{-- Points d'attention : ce qui demande une action --}}
    <h2 style="font-size:1rem;margin-bottom:.9rem">Points d'attention</h2>

    <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.75rem">
        @if ($pipelineActif === false)
            <div class="banner">
                <i data-lucide="power"></i>
                <div>
                    <strong>Pipeline natif désactivé</strong>
                    <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                        <code>DOCUMENT_PIPELINE_V2=false</code> : les documents sont traités par l'ancien
                        pipeline (lecture PHPWord). R1→R7 ne sont pas exercés en production.
                    </div>
                </div>
            </div>
        @endif

        @if ($clarificationsEnAttente > 0)
            <div class="banner">
                <i data-lucide="circle-help"></i>
                <div>
                    <strong>{{ $clarificationsEnAttente }} passage(s) à confirmer</strong>
                    <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                        Sur {{ $documentsAvecClarifications }} document(s). Ces passages attendent une
                        décision utilisateur : sans réponse, ils restent ambigus pour la mise en forme.
                    </div>
                </div>
            </div>
        @endif

        @if ($coutTotal['failures'] > 0 && $fuite >= 20)
            <div class="banner">
                <i data-lucide="trending-down"></i>
                <div>
                    <strong>{{ $fuite }} % de tentatives échouées</strong>
                    <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                        Un taux élevé signale une instabilité du fournisseur plutôt qu'une erreur de
                        facturation. À surveiller : chaque échec est facturé sans rien produire.
                    </div>
                </div>
            </div>
        @endif

        @if ($coutTotal['fallbacks'] > 0)
            <div class="banner">
                <i data-lucide="shuffle"></i>
                <div>
                    <strong>{{ $coutTotal['fallbacks'] }} bascule(s) de fournisseur</strong>
                    <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                        Le repli DeepSeek a été utilisé : le tarif change en cours de route. Le registre
                        conserve le modèle d'origine de chaque bascule.
                    </div>
                </div>
            </div>
        @endif

        @if ($totalDocuments > 0 && $avecStructureNative === 0)
            <div class="banner">
                <i data-lucide="database"></i>
                <div>
                    <strong>Aucune structure native en base</strong>
                    <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                        Aucun document n'a encore été traité par le nouveau pipeline. Activer
                        <code>DOCUMENT_PIPELINE_V2</code> en mode <code>auto</code> permet de l'exercer
                        avec repli sur l'ancien en cas d'échec.
                    </div>
                </div>
            </div>
        @endif

        @if (
            $pipelineActif !== false
            && $clarificationsEnAttente === 0
            && $coutTotal['failures'] === 0
            && $avecStructureNative > 0
        )
            <div class="card" style="margin:0">
                <p style="margin:0;font-size:.9rem;color:var(--color-text-secondary)">
                    Aucun point d'attention : le pipeline fonctionne et le registre ne relève ni échec
                    ni passage en attente.
                </p>
            </div>
        @endif
    </div>

    {{-- Répartition des documents par statut --}}
    <div class="split-grid">
        <section class="card">
            <h2 class="card-title" style="margin-bottom:.9rem">Documents par statut</h2>

            @if ($parStatut === [])
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">Aucun document.</p>
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
                            <tr><th>Statut</th><th>Documents</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($parStatut as $statut => $total)
                                <tr>
                                    <td data-label="Statut">{{ $libelles[$statut] ?? $statut }}</td>
                                    <td data-label="Documents">{{ $total }}</td>
                                    <td>
                                        <a href="{{ route('admin.documents', ['status' => $statut]) }}"
                                           style="font-size:.8rem">Voir</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card">
            <h2 class="card-title" style="margin-bottom:.9rem">Dernières tentatives facturées</h2>

            @if ($dernieresLignes->isEmpty())
                <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                    Le registre est vide : aucun appel IA n'a été facturé.
                </p>
            @else
                <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem;font-size:.82rem">
                    @foreach ($dernieresLignes as $ligne)
                        <li style="display:flex;align-items:center;gap:.5rem">
                            <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:{{ $ligne->succeeded ? 'var(--color-success)' : 'var(--color-danger)' }}"></span>
                            <span style="font-family:var(--font-mono);font-size:.75rem;color:var(--color-text-muted)"
                                  title="{{ $ligne->created_at }}">
                                {{ $ligne->created_at?->format('d/m H:i') }}
                            </span>
                            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $ligne->model }}
                            </span>
                            <span style="color:var(--color-text-muted)">{{ $ligne->cost_credits }} cr.</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Répartition par moteur IA --}}
    {{--
        Ce que cet écran NE montre pas, volontairement : aucune latence, aucun
        opérateur de paiement, aucune région. Ces données n'existent pas en base
        (vérifié : `ai_usage_ledger` n'a pas de colonne de durée, `kpay_payments`
        ne stocke pas l'opérateur). Les afficher obligerait à les inventer, et un
        chiffre inventé sur un tableau d'exploitation est plus dangereux qu'une
        absence : il oriente une décision.
    --}}
    @if ($parMoteur !== [])
        <section class="card" style="margin-top:1.5rem">
            <h2 class="card-title" style="margin-bottom:.25rem">Moteurs &amp; modèles IA</h2>
            <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.1rem">
                Quel modèle porte le coût, et lequel échoue. Un total stable peut masquer
                un modèle qui bascule tous ses appels en repli.
            </p>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Modèle</th>
                            <th>Fournisseur</th>
                            <th>Appels</th>
                            <th>Échecs</th>
                            <th>Tokens</th>
                            <th>Crédits</th>
                            <th>Part du coût</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($parMoteur as $moteur)
                            @php
                                $part = $creditsTotaux > 0
                                    ? round($moteur['credits'] / $creditsTotaux * 100, 1)
                                    : 0;
                                // Un taux d'échec élevé est le signal qui justifie de
                                // changer de fournisseur : on le met en évidence.
                                $tauxEchec = $moteur['attempts'] > 0
                                    ? round($moteur['failures'] / $moteur['attempts'] * 100, 1)
                                    : 0;
                            @endphp
                            <tr>
                                <td data-label="Modèle" style="font-family:var(--font-mono);font-size:.75rem">
                                    {{ $moteur['model'] }}
                                </td>
                                <td data-label="Fournisseur" style="font-size:.78rem">
                                    {{ $moteur['provider'] }}
                                </td>
                                <td data-label="Appels" class="num" style="font-size:.78rem">
                                    {{ number_format($moteur['attempts'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Échecs" class="num" style="font-size:.78rem">
                                    @if ($moteur['failures'] > 0)
                                        <span style="color:var(--color-danger)">
                                            {{ $moteur['failures'] }} ({{ $tauxEchec }} %)
                                        </span>
                                    @else
                                        <span style="color:var(--color-text-muted)">0</span>
                                    @endif
                                </td>
                                <td data-label="Tokens" class="num" style="font-size:.78rem">
                                    {{ number_format($moteur['tokens'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Crédits" class="num" style="font-size:.78rem">
                                    {{ number_format($moteur['credits'], 0, ',', ' ') }}
                                </td>
                                <td data-label="Part du coût">
                                    <div style="display:flex;align-items:center;gap:.5rem">
                                        <div class="progress" style="width:70px;flex-shrink:0">
                                            <div class="progress-bar" style="width:{{ $part }}%"></div>
                                        </div>
                                        <span style="font-size:.75rem;color:var(--color-text-muted)">{{ $part }} %</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p style="font-size:.78rem;color:var(--color-text-muted);margin:1rem 0 0">
                Les tentatives échouées sont comptées et facturées : elles représentent ce qui est
                payé au fournisseur sans produire de résultat. Le rapport de rentabilité détaillé
                se lit sur <a href="{{ route('admin.billing') }}">Facturation</a>.
            </p>
        </section>
    @endif
@endsection
