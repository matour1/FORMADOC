@extends('layouts.app')

@section('title', 'Conversation — Assistant IA')

@section('content')
    <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">

        {{-- Barre de navigation --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ route('chat.index') }}" class="flex items-center gap-1 text-secondary hover:text-primary transition-colors">
                    <span class="material-symbols-outlined">arrow_back</span>
                    <span class="font-body-md">Mes conversations</span>
                </a>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-caption text-caption text-on-surface-variant">Solde : {{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }} crédits</span>
                <a href="{{ route('account.index') }}" class="font-caption text-caption text-primary hover:underline">Acheter</a>
            </div>
        </div>

        {{-- Alertes --}}
        @if (session('error'))
            <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                <span class="material-symbols-outlined text-error">error</span>
                <p class="text-body-md text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-gutter">

            {{-- Sidebar sessions --}}
            <aside class="lg:col-span-1 bg-surface-container-lowest border border-outline-variant rounded-xl p-4 h-fit">
                <p class="font-label-mono text-label-mono text-secondary uppercase mb-3">Conversations</p>
                <ul class="space-y-1">
                    @foreach ($sessions as $session)
                        <li>
                            <a href="{{ route('chat.show', $session) }}"
                               class="block py-2.5 px-3 rounded-lg transition-colors font-caption text-caption
                                      {{ $session->id === $chatSession->id ? 'bg-primary-fixed text-on-primary-fixed font-semibold' : 'text-on-surface-variant hover:bg-surface-container-high' }}">
                                {{ \Illuminate\Support\Str::limit($session->title ?: 'Sans titre', 40) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <form action="{{ route('chat.send') }}" method="POST" class="mt-4">
                    @csrf
                    <input type="hidden" name="message" value="Bonjour, peux-tu m'aider à structurer mon rapport ?">
                    <button type="submit"
                            class="w-full flex items-center justify-center gap-2 bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-fixed px-4 py-2.5 rounded-lg font-caption text-caption transition-colors">
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        Nouvelle conversation
                    </button>
                </form>
            </aside>

            {{-- Fil de discussion --}}
            <div class="lg:col-span-3 flex flex-col bg-surface-container-lowest border border-outline-variant rounded-xl">

                {{-- En-tête de session --}}
                <div class="border-b border-outline-variant px-6 py-4">
                    <h2 class="font-h2 text-h2 text-on-surface truncate">{{ $chatSession->title ?: 'Sans titre' }}</h2>
                    <p class="font-caption text-caption text-on-surface-variant mt-1">
                        @if ($chatSession->model_used)
                            Modèle actuel : <span class="font-mono">{{ $chatSession->model_used }}</span>
                        @endif
                        — {{ $chatSession->total_cost_credits }} crédits consommés au total
                    </p>
                </div>

                {{-- Messages --}}
                <div class="flex-1 px-6 py-6 space-y-5 max-h-[60vh] overflow-y-auto" id="chat-messages">
                    @forelse ($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] md:max-w-[75%]
                                {{ $message->role === 'user'
                                    ? 'bg-primary text-on-primary rounded-2xl rounded-br-md px-4 py-3'
                                    : 'bg-surface-container rounded-2xl rounded-bl-md px-4 py-3 text-on-surface' }}">
                                <p class="font-body-md whitespace-pre-wrap">{{ $message->content }}</p>
                                @if ($message->role === 'assistant' && $message->model_used)
                                    <p class="font-caption text-caption mt-2 opacity-70">
                                        <span class="font-mono">{{ $message->model_used }}</span>
                                        @if ($message->cost_credits > 0)
                                            — {{ $message->cost_credits }} crédit{{ $message->cost_credits > 1 ? 's' : '' }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-12">
                            <span class="material-symbols-outlined text-[48px] text-outline">forum</span>
                            <p class="font-body-md text-body-md text-on-surface-variant mt-3">
                                Aucun message. Posez votre première question !
                            </p>
                        </div>
                    @endforelse
                </div>

                {{-- Formulaire d'envoi --}}
                <form action="{{ route('chat.send', $chatSession) }}" method="POST" class="border-t border-outline-variant p-4">
                    @csrf
                    <div class="flex gap-3">
                        <textarea name="message" id="chat-input" rows="2" required maxlength="12000" placeholder="Votre message… (coût estimé : modèles du plan)"
                                  class="flex-1 rounded-lg border-outline-variant bg-surface-container-low px-4 py-3 font-body-md text-body-md focus:border-primary focus:ring-primary resize-y"></textarea>
                        <button type="submit" id="chat-send"
                                class="shrink-0 inline-flex items-center gap-2 bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-fixed px-5 py-3 rounded-lg font-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined text-[20px]">send</span>
                            Envoyer
                        </button>
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant mt-2">
                        Le coût en crédits est estimé avant l'envoi et affiché sur la réponse. En cas d'échec, vos crédits sont remboursés.
                    </p>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Scrolle en bas de la conversation au chargement
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('chat-messages');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    </script>
@endpush
