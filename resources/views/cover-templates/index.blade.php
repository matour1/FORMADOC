@extends('layouts.app')

@section('title', 'Modèles de page de garde')

@section('content')
<div class="max-w-6xl">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.6rem">
        <div class="page-header" style="margin-bottom:0">
            <div>
                <span class="eyebrow">Pages de garde</span>
                <h1>Modèles de page de garde</h1>
                <p>
                    Personnalisez vos couvertures et réutilisez-les sur tous vos rapports.
                </p>
            </div>
        </div>
        <a href="{{ route('cover-templates.create') }}" class="btn btn-primary">
            <i data-lucide="plus" style="width:16px;height:16px"></i>
            Nouveau modèle
        </a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.1rem">
        @forelse ($templates as $tpl)
            <article class="card" style="display:flex;flex-direction:column;gap:.9rem">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem">
                    <div style="min-width:0">
                        <h2 style="font-weight:600;font-size:.98rem">{{ $tpl->name }}</h2>
                        <p style="color:var(--color-text-secondary);font-size:.82rem;margin-top:.25rem">{{ $tpl->description ?: 'Aucune description.' }}</p>
                    </div>
                    @if ($tpl->is_public)
                        <span class="badge badge-success">Public</span>
                    @else
                        <span class="badge">Privé</span>
                    @endif
                </div>
                <div style="font-size:.75rem;color:var(--color-text-muted)">
                    Mis à jour {{ $tpl->updated_at?->diffForHumans() }}
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;border-top:1px solid var(--color-border);padding-top:.8rem">
                    <a href="{{ route('cover-templates.edit', $tpl) }}" class="btn btn-ghost btn-sm">
                        <i data-lucide="pencil" style="width:14px;height:14px"></i>
                        Éditer
                    </a>
                    <form method="POST" action="{{ route('cover-templates.duplicate', $tpl) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <i data-lucide="copy" style="width:14px;height:14px"></i>
                            Dupliquer
                        </button>
                    </form>
                    <form method="POST" action="{{ route('cover-templates.destroy', $tpl) }}"
                          onsubmit="return confirm('Supprimer ce modèle ?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-correction)">
                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                            Supprimer
                        </button>
                    </form>
                </div>
            </article>
        @empty
            <div style="grid-column:1 / -1;border:1px dashed var(--color-border);border-radius:var(--radius);background:var(--color-surface);padding:2.5rem 1.5rem;text-align:center">
                <i data-lucide="inbox" style="width:44px;height:44px;color:var(--color-text-muted)"></i>
                <p style="color:var(--color-text-secondary);font-size:.9rem;margin-top:1rem">Aucun modèle pour le moment.</p>
                <a href="{{ route('cover-templates.create') }}" class="btn btn-primary" style="margin-top:1rem">
                    <i data-lucide="plus" style="width:16px;height:16px"></i>
                    Créer le premier modèle
                </a>
            </div>
        @endforelse
    </div>
</div>
@endsection
