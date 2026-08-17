@extends('layouts.app')

@section('title', 'Modèles de page de garde')

@section('content')
<div class="mx-auto max-w-6xl px-6 py-10">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Modèles de page de garde</h1>
            <p class="mt-1 text-sm text-slate-500">
                Personnalisez vos couvertures et réutilisez-les sur tous vos rapports.
            </p>
        </div>
        <a href="{{ route('cover-templates.create') }}"
           class="inline-flex items-center gap-2 rounded-full bg-[#004AC6] px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-[#2563eb]">
            <span class="material-symbols-outlined text-[18px]">add</span>
            Nouveau modèle
        </a>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($templates as $tpl)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ $tpl->name }}</h2>
                        <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ $tpl->description ?: 'Aucune description.' }}</p>
                    </div>
                    @if ($tpl->is_public)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Public</span>
                    @else
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Privé</span>
                    @endif
                </div>
                <div class="mt-4 flex items-center justify-between text-xs text-slate-400">
                    <span>Mis à jour {{ $tpl->updated_at?->diffForHumans() }}</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('cover-templates.edit', $tpl) }}"
                       class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Éditer
                    </a>
                    <form method="POST" action="{{ route('cover-templates.duplicate', $tpl) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Dupliquer
                        </button>
                    </form>
                    <form method="POST" action="{{ route('cover-templates.destroy', $tpl) }}"
                          onsubmit="return confirm('Supprimer ce modèle ?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="rounded-full border border-rose-200 px-3 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50">
                            Supprimer
                        </button>
                    </form>
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                <span class="material-symbols-outlined text-5xl text-slate-300">inbox</span>
                <p class="mt-4 text-sm text-slate-500">Aucun modèle pour le moment.</p>
                <a href="{{ route('cover-templates.create') }}"
                   class="mt-4 inline-flex items-center gap-2 rounded-full bg-[#004AC6] px-4 py-2 text-sm font-medium text-white">
                    Créer le premier modèle
                </a>
            </div>
        @endforelse
    </div>
</div>
@endsection
