@extends('layouts.admin')

@section('title', 'Usage IA')

@section('content')
    <div style="margin-bottom:1.5rem">
        <span class="eyebrow">Traçabilité</span>
        <h1 style="font-size:1.5rem;margin:.25rem 0 .4rem">Registre d'usage IA</h1>
        <p style="color:var(--color-text-secondary);font-size:.9rem;margin:0">
            Chaque tentative facturée, retries et échecs inclus. Le total du rapport de rentabilité
            est la somme de ces lignes — c'est ce qui rend la facturation vérifiable.
        </p>
    </div>

    {{-- Contrôle de recalculabilité : le coût stocké est-il dérivable des tokens ? --}}
    @php $controle = $recalculabilite; @endphp

    @if ($controle['ecarts'] > 0)
        <div class="banner" style="margin-bottom:1.25rem">
            <i data-lucide="alert-triangle"></i>
            <div>
                <strong>{{ $controle['ecarts'] }} écart(s) de recalculabilité</strong>
                <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                    Le coût stocké ne correspond plus au calcul depuis les tokens et la grille
                    tarifaire. Un tarif de <code>config/openrouter.php</code> a probablement changé
                    sans retraitement des lignes historiques. Les lignes stockées restent la
                    référence facturée.
                </div>
                @foreach ($controle['exemples'] as $ecart)
                    <div style="font-size:.78rem;color:var(--color-text-muted);margin-top:.35rem">
                        Ligne {{ $ecart['id'] }} ({{ $ecart['model'] }}) :
                        {{ $ecart['credits_stockes'] }} stockés, {{ $ecart['credits_recalcules'] }} recalculés
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="banner banner-success" style="margin-bottom:1.25rem">
            <i data-lucide="badge-check"></i>
            <div>
                <strong>Coût recalculable</strong>
                <div style="font-size:.85rem;color:var(--color-text-secondary);margin-top:.25rem">
                    {{ $controle['verifiees'] }} ligne(s) vérifiée(s), cohérentes avec les tokens et la
                    grille tarifaire.
                    @if ($controle['estimees'] > 0)
                        {{ $controle['estimees'] }} ligne(s) à tokens estimés exclues du contrôle
                        (échec sans réponse).
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Filtres --}}
    <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <div>
            <label for="model" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Modèle</label>
            <input type="text" id="model" name="model" value="{{ $filtres['model'] }}" class="form-control"
                   placeholder="deepseek…">
        </div>
        <div>
            <label for="user" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Utilisateur (id)</label>
            <input type="text" id="user" name="user" value="{{ $filtres['user'] }}" class="form-control" style="max-width:110px">
        </div>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem">
            <input type="checkbox" name="failures" value="1" @checked($filtres['failures'])>
            Échecs seulement
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem">
            <input type="checkbox" name="fallbacks" value="1" @checked($filtres['fallbacks'])>
            Bascules seulement
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrer</button>
        <a href="{{ route('admin.usage') }}" class="btn btn-ghost btn-sm">Réinitialiser</a>
    </form>

    <section class="card">
        @if ($lignes->isEmpty())
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">
                Aucune ligne pour ces critères.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Modèle</th>
                            <th>Tâche</th>
                            <th>Référence</th>
                            <th>Entrée</th>
                            <th>Sortie</th>
                            <th>Essai</th>
                            <th>Coût</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lignes as $ligne)
                            <tr>
                                <td data-label="Date" style="font-family:var(--font-mono);font-size:.75rem;white-space:nowrap">
                                    {{ $ligne->created_at?->format('d/m H:i:s') }}
                                </td>
                                <td data-label="Modèle" style="font-family:var(--font-mono);font-size:.75rem">
                                    {{ $ligne->model }}
                                    @if ($ligne->is_fallback)
                                        <span style="color:var(--color-text-muted)">← {{ $ligne->fallback_from }}</span>
                                    @endif
                                </td>
                                <td data-label="Tâche" style="font-size:.78rem">{{ $ligne->task_type }}</td>
                                <td data-label="Référence" style="font-size:.78rem;color:var(--color-text-muted)">
                                    {{ $ligne->reference ?? '—' }}
                                </td>
                                <td data-label="Entrée" style="font-size:.78rem">
                                    {{ $ligne->input_tokens }}
                                    @if ($ligne->estimated)
                                        <span style="color:var(--color-text-muted)" title="Tokens estimés : l'appel a échoué avant la réponse">≈</span>
                                    @endif
                                </td>
                                <td data-label="Sortie" style="font-size:.78rem">{{ $ligne->output_tokens }}</td>
                                <td data-label="Essai" style="font-size:.78rem">
                                    {{ $ligne->attempt }}
                                    @if (! $ligne->succeeded)
                                        <span style="color:var(--color-danger)" title="{{ $ligne->error }}">✕</span>
                                    @endif
                                </td>
                                <td data-label="Coût" style="font-size:.78rem;white-space:nowrap">
                                    {{ $ligne->cost_credits }} cr.
                                    <span style="color:var(--color-text-muted);font-size:.72rem">
                                        ({{ number_format($ligne->cost_usd, 6, ',', ' ') }} $)
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem">
                {{ $lignes->links() }}
            </div>
        @endif
    </section>
@endsection
