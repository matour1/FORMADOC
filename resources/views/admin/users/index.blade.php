@extends('layouts.admin')

@section('title', 'Comptes utilisateurs')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Comptes utilisateurs</h1>
        <p>
            Recherche, droits, suspension et ajustement de crédits. Toute action qui
            modifie un compte est journalisée avec son auteur et son motif.
        </p>
    </div>

    @if (session('success'))
        <div class="banner banner-success">
            <i data-lucide="check-circle"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif
    @if (session('error'))
        <div class="banner banner-danger">
            <i data-lucide="alert-circle"></i>
            <div>{{ session('error') }}</div>
        </div>
    @endif

    <div class="stat-grid">
        <div class="stat-item">
            <span class="stat-label">Comptes</span>
            <span class="stat-value tabular">{{ number_format($total, 0, ',', ' ') }}</span>
            <span class="stat-hint">tous statuts confondus</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Administrateurs</span>
            <span class="stat-value tabular">{{ number_format($totalAdmins, 0, ',', ' ') }}</span>
            <span class="stat-hint">accès à cet espace</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Suspendus</span>
            <span class="stat-value tabular">{{ number_format($totalSuspendus, 0, ',', ' ') }}</span>
            <span class="stat-hint">connexion refusée</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Crédits en circulation</span>
            <span class="stat-value tabular">{{ number_format($creditsEnCirculation, 0, ',', ' ') }}</span>
            <span class="stat-hint">solde cumulé de tous les comptes</span>
        </div>
    </div>

    <section class="card" style="margin-bottom:1.5rem">
        <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1;min-width:220px">
                <label for="q" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Recherche</label>
                <input type="search" id="q" name="q" value="{{ $filtres['q'] }}"
                       class="form-control" placeholder="Nom ou adresse e-mail">
            </div>

            <div>
                <label for="sort" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Tri</label>
                <select id="sort" name="sort" class="form-control">
                    <option value="id" @selected($filtres['sort'] === 'id')>Plus récents</option>
                    <option value="recent" @selected($filtres['sort'] === 'recent')>Dernière activité</option>
                    <option value="name" @selected($filtres['sort'] === 'name')>Nom</option>
                    <option value="credits" @selected($filtres['sort'] === 'credits')>Crédits (décroissant)</option>
                </select>
            </div>

            <label class="switch" style="margin-bottom:.5rem">
                <input type="checkbox" name="admins" value="1" @checked($filtres['admins'])>
                <span class="track"></span>
                <span style="font-size:.85rem">Administrateurs</span>
            </label>

            <label class="switch" style="margin-bottom:.5rem">
                <input type="checkbox" name="suspended" value="1" @checked($filtres['suspended'])>
                <span class="track"></span>
                <span style="font-size:.85rem">Suspendus</span>
            </label>

            <label class="switch" style="margin-bottom:.5rem">
                <input type="checkbox" name="with_credits" value="1" @checked($filtres['with_credits'])>
                <span class="track"></span>
                <span style="font-size:.85rem">Avec des crédits</span>
            </label>

            <button type="submit" class="btn btn-primary">Filtrer</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Réinitialiser</a>
        </form>
    </section>

    @if ($utilisateurs->isEmpty())
        <div class="card">
            <p style="margin:0;color:var(--color-text-secondary);font-size:.9rem">
                Aucun compte ne correspond à ces critères.
            </p>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Compte</th>
                        <th>Inscription</th>
                        <th>Crédits</th>
                        <th>Plan</th>
                        <th>État</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($utilisateurs as $utilisateur)
                        <tr>
                            <td data-label="Compte">
                                <div style="display:flex;align-items:center;gap:.6rem">
                                    <div class="avatar" style="width:30px;height:30px;font-size:.75rem">
                                        {{ strtoupper(substr($utilisateur->name, 0, 1)) }}
                                    </div>
                                    <div style="min-width:0">
                                        <div style="font-weight:600;font-size:.85rem">{{ $utilisateur->name }}</div>
                                        <div class="mono" style="font-size:.7rem;color:var(--color-text-muted)">
                                            {{ $utilisateur->email }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Inscription" style="font-size:.8rem">
                                {{ $utilisateur->created_at?->format('d/m/Y') }}
                            </td>
                            <td data-label="Crédits" class="num" style="font-size:.82rem">
                                {{ number_format($utilisateur->credits_balance, 0, ',', ' ') }}
                            </td>
                            <td data-label="Plan" style="font-size:.8rem">
                                {{ $utilisateur->currentPlanSlug() }}
                            </td>
                            <td data-label="État">
                                <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                                    @if ($utilisateur->is_admin)
                                        <span class="badge badge-info">Admin</span>
                                    @endif
                                    @if ($utilisateur->is_suspended)
                                        <span class="badge badge-danger">Suspendu</span>
                                    @endif
                                    @if (! $utilisateur->is_admin && ! $utilisateur->is_suspended)
                                        <span class="badge">Actif</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.users.show', $utilisateur) }}" style="font-size:.8rem">
                                    Ouvrir
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination">
            {{ $utilisateurs->links() }}
        </div>
    @endif
@endsection
