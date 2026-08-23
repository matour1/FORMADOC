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

    <div class="tabs" role="tablist">
        <button class="tab active" data-filter="all" role="tab" aria-selected="true">Tous</button>
        <button class="tab" data-filter="public" role="tab" aria-selected="false">Publics</button>
        <button class="tab" data-filter="private" role="tab" aria-selected="false">Mes modèles</button>
    </div>

    <div class="template-grid" id="coverTemplateGrid">
        @forelse ($templates as $tpl)
            <article class="template-card" data-public="{{ $tpl->is_public ? 'public' : 'private' }}" style="display:flex;flex-direction:column;cursor:default">
                <a href="{{ route('cover-templates.edit', $tpl) }}" style="display:block" aria-label="Éditer {{ $tpl->name }}">
                    <div class="template-thumb">
                        <div class="tl title"></div>
                        <div class="tl w80"></div>
                        <div class="tl w60"></div>
                        <div class="tl w80"></div>
                        <div class="tl w40"></div>
                    </div>
                </a>
                <div class="template-body" style="padding:.85rem .95rem;display:flex;flex-direction:column;gap:.45rem;flex:1">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                        <div class="t-name">{{ $tpl->name }}</div>
                        @if ($tpl->is_public)
                            <span class="badge badge-success">Public</span>
                        @else
                            <span class="badge">Privé</span>
                        @endif
                    </div>
                    <div class="t-cat">{{ $tpl->description ?: 'Couverture personnalisée' }} · {{ $tpl->updated_at?->diffForHumans() }}</div>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:auto;padding-top:.7rem;border-top:1px solid var(--color-border)">
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Filtres Tous / Publics / Mes modèles
    const tabs = document.querySelectorAll('.tabs .tab[data-filter]');
    const cards = document.querySelectorAll('#coverTemplateGrid .template-card');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const f = tab.dataset.filter;
            tabs.forEach(t => {
                t.classList.toggle('active', t === tab);
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
            });
            cards.forEach(card => {
                const show = f === 'all' || card.dataset.public === f;
                card.style.display = show ? '' : 'none';
            });
        });
    });
});
</script>
@endpush
@endsection
