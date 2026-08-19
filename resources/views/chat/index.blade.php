@extends('layouts.app')

@section('title', 'Assistant IA')

@section('content')
    <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

        {{-- En-tête de page --}}
        <div class="mb-8 md:mb-12">
            <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Assistant IA</p>
            <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                Discuter avec l'assistant
            </h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-2xl">
                Posez vos questions sur la mise en forme de rapports, la rédaction,
                la structure de vos documents. Chaque message affiche son coût en
                crédits <strong>avant</strong> l'envoi.
            </p>
        </div>

        {{-- Alertes --}}
        @if (session('error'))
            <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-error">error</span>
                <p class="text-body-md text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Solde rapide --}}
        <div class="flex flex-wrap items-center gap-4 mb-6 bg-surface-container-lowest border border-outline-variant rounded-xl p-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">savings</span>
                <span class="font-body-md font-semibold text-on-surface">Solde : {{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }} crédits</span>
            </div>
            <a href="{{ route('account.index') }}" class="font-caption text-caption text-primary hover:underline">
                Acheter des crédits →
            </a>
        </div>

        {{-- Nouvelle conversation --}}
        <form action="{{ route('chat.send') }}" method="POST" class="mb-8">
            @csrf
            <div class="flex gap-3">
                <input type="text" name="message" required placeholder="Ex. : Comment structurer un rapport de stage de 30 pages ?"
                       class="flex-1 rounded-lg border-outline-variant bg-surface-container-lowest px-4 py-3 font-body-md text-body-md focus:border-primary focus:ring-primary">
                <button type="submit"
                        class="shrink-0 inline-flex items-center gap-2 bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-fixed px-6 py-3 rounded-lg font-body-md font-semibold transition-colors">
                    <span class="material-symbols-outlined text-[20px]">send</span>
                    Envoyer
                </button>
            </div>
            <p class="font-caption text-caption text-on-surface-variant mt-2">
                Une nouvelle conversation sera créée. Coût estimé affiché après envoi.
            </p>
        </form>

        {{-- Historique des sessions --}}
        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
            <p class="font-label-mono text-label-mono text-secondary uppercase mb-4">Mes conversations</p>

            @if ($sessions->isEmpty())
                <p class="font-body-md text-body-md text-on-surface-variant py-8 text-center">
                    Aucune conversation pour le moment. Commencez à discuter ci-dessus !
                </p>
            @else
                <ul class="divide-y divide-outline-variant/60">
                    @foreach ($sessions as $session)
                        <li>
                            <a href="{{ route('chat.show', $session) }}"
                               class="flex items-center justify-between py-4 hover:bg-surface-container-low rounded-lg px-3 -mx-3 transition-colors">
                                <div class="min-w-0 flex items-center gap-3">
                                    <span class="material-symbols-outlined text-primary shrink-0">chat</span>
                                    <div class="min-w-0">
                                        <p class="font-body-md font-semibold text-on-surface truncate">{{ $session->title ?: 'Sans titre' }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">
                                            {{ $session->messages_count }} message{{ $session->messages_count > 1 ? 's' : '' }}
                                            — {{ $session->total_cost_credits }} crédits
                                            — {{ $session->updated_at->diffForHumans() }}
                                        </p>
                                    </div>
                                </div>
                                <span class="material-symbols-outlined text-outline shrink-0">chevron_right</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
