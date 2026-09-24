@extends('layouts.admin')

@section('title', 'Rentabilité')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Facturation</span>
        <h1>Rapport de rentabilité</h1>
        <p>
            Les mêmes chiffres que <code>php artisan billing:report</code>, calculés par le même
            service — deux calculs parallèles finiraient par diverger.
        </p>
    </div>

    {{-- Filtre de période --}}
    <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.5rem">
        <div>
            <label for="from" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Du</label>
            <input type="date" id="from" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div>
            <label for="to" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Au</label>
            <input type="date" id="to" name="to" value="{{ $to }}" class="form-control">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrer</button>
        @if ($from || $to)
            <a href="{{ route('admin.billing') }}" class="btn btn-ghost btn-sm">Tout</a>
        @endif
    </form>

    @php
        // Comparaison coût réel / prix de grille théorique. Un ratio inférieur à 1
        // signifie que le coefficient de rentabilité est absorbé par des coûts non
        // refacturés (échecs, remboursements) — c'est un choix pour les échecs,
        // mais cela doit rester visible plutôt que découvert dans la comptabilité.
        $ratio = $theorique > 0 ? round($totaux['credits'] / $theorique, 2) : null;
    @endphp

    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Coût réel</span>
            <span class="stat-value tabular">{{ number_format($totaux['credits'], 0, ',', ' ') }}</span>
            <span class="stat-hint">crédits · {{ number_format($totaux['usd'], 6, ',', ' ') }} USD</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Prix théorique grille</span>
            <span class="stat-value tabular">{{ number_format($theorique, 0, ',', ' ') }}</span>
            <span class="stat-hint">
                crédits · coefficient {{ number_format($coefficient, 2, ',', ' ') }}×
                @if ($ratio !== null)
                    · reversibilité {{ $ratio }}
                @endif
            </span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Tentatives</span>
            <span class="stat-value tabular">{{ number_format($totaux['attempts'], 0, ',', ' ') }}</span>
            <span class="stat-hint">{{ $totaux['failures'] }} échec(s) · {{ $totaux['fallbacks'] }} bascule(s)</span>
        </div>

        <div class="stat-item">
            <span class="stat-label">Tokens</span>
            <span class="stat-value tabular">{{ number_format($totaux['tokens'], 0, ',', ' ') }}</span>
            <span class="stat-hint">entrée et sortie cumulées</span>
        </div>
    </div>

    <section class="card" style="margin-bottom:1.5rem">
        <h2 class="card-title" style="margin-bottom:.4rem">Répartition par modèle</h2>
        <p style="font-size:.82rem;color:var(--color-text-muted);margin:0 0 1rem">
            Un même modèle appelé via deux fournisseurs n'est pas le même poste de coût : le tarif
            diffère, et la ligne doit rester distincte.
        </p>

        @if ($parModele === [])
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">Registre vide.</p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Modèle</th>
                            <th>Fournisseur</th>
                            <th>Tentatives</th>
                            <th>Échecs</th>
                            <th>Tokens</th>
                            <th>Crédits</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($parModele as $ligne)
                            <tr>
                                <td data-label="Modèle" style="font-family:var(--font-mono);font-size:.78rem">{{ $ligne['model'] }}</td>
                                <td data-label="Fournisseur">
                                    {{ $ligne['provider'] }}
                                    @if ($ligne['provider'] === 'deepseek_fallback')
                                        <span style="font-size:.7rem;color:var(--color-text-muted)">(repli)</span>
                                    @endif
                                </td>
                                <td data-label="Tentatives">{{ $ligne['attempts'] }}</td>
                                <td data-label="Échecs">
                                    {{ $ligne['failures'] }}
                                    @if ($ligne['failures'] > 0)
                                        <span style="color:var(--color-danger);font-size:.75rem">
                                            ({{ round($ligne['failures'] / $ligne['attempts'] * 100) }} %)
                                        </span>
                                    @endif
                                </td>
                                <td data-label="Tokens">{{ number_format($ligne['tokens'], 0, ',', ' ') }}</td>
                                <td data-label="Crédits">{{ number_format($ligne['credits'], 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="card" style="background:var(--color-surface-2);border-color:transparent">
        <p style="font-size:.82rem;color:var(--color-text-secondary);margin:0">
            <strong>Lecture des chiffres.</strong> Le coût réel inclut les retries et les échecs :
            le fournisseur les facture, donc ils sont dus. Le <em>prix théorique</em> applique la
            grille tarifaire à ces mêmes tokens. Un écart entre les deux ne signale pas une erreur :
            il mesure la part absorbée par les échecs remboursés aux utilisateurs.
        </p>
    </div>
@endsection
