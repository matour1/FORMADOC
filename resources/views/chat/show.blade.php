@extends('layouts.app')

@section('title', 'Conversation — Assistant IA')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Assistant IA</span>
            <h1>{{ $chatSession->title ?: 'Nouvelle conversation' }}</h1>
            <p>
                @if ($chatSession->model_used)
                    Modèle : <span class="mono">{{ $chatSession->model_used }}</span>
                @endif
                — {{ $chatSession->total_cost_credits }} crédit(s) consommé(s) au total
            </p>
        </div>
        <a href="{{ route('chat.index') }}" class="btn btn-ghost btn-sm">
            <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Mes conversations
        </a>
    </div>

    {{-- Confirmation de coût AVANT envoi (exigence A) --}}
    @if ($pendingCost = session('pending_cost'))
        <div class="banner banner-warning">
            <i data-lucide="info"></i>
            <div style="flex:1">
                <strong>Coût estimé : {{ $pendingCost['credits'] }} crédit(s)</strong>
                <div style="font-size:.82rem">(plan {{ $pendingCost['plan'] }}, estimation avant exécution — ajustement automatique après usage)</div>
            </div>
            <form action="{{ route('chat.send', $chatSession) }}" method="POST" style="display:inline-flex;gap:.5rem">
                @csrf
                <input type="hidden" name="message" value="{{ $pendingCost['message'] }}">
                <input type="hidden" name="confirm_cost" value="1">
                <button type="submit" class="btn btn-primary btn-sm">Confirmer et envoyer</button>
                <a href="{{ route('chat.show', $chatSession) }}" class="btn btn-ghost btn-sm">Annuler</a>
            </form>
        </div>
    @endif

    {{-- Alerte quota IA épuisé (le chat reste accessible, la réponse sera refusée) --}}
    @php
        $quotaStatus = app(\App\Services\Billing\QuotaService::class)->status(auth()->user());
        $aiExhausted = $quotaStatus['ai']['remaining'] <= 0;
    @endphp
    @if ($aiExhausted)
        <div class="banner banner-danger">
            <i data-lucide="alert-triangle"></i>
            <div>
                <strong>Quota IA mensuel atteint</strong>
                <div style="font-size:.82rem">Passez à un plan supérieur ou attendez la prochaine période pour envoyer de nouveaux messages.</div>
            </div>
        </div>
    @endif

    <div class="chat-layout">

        {{-- Sidebar conversations --}}
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <span class="chat-sidebar-title">Conversations</span>
                <a href="{{ route('chat.index') }}" class="icon-btn" title="Nouvelle conversation" style="width:30px;height:30px">
                    <i data-lucide="square-pen" style="width:15px;height:15px"></i>
                </a>
            </div>

            @foreach ($sessions as $session)
                <a href="{{ route('chat.show', $session) }}"
                   class="chat-item {{ $session->id === $chatSession->id ? 'active' : '' }}">
                    <span class="chat-item-icon"><i data-lucide="message-circle"></i></span>
                    <span class="chat-item-main">
                        <span class="chat-item-name">{{ \Illuminate\Support\Str::limit($session->title ?: 'Sans titre', 32) }}</span>
                        <span class="chat-item-sub">{{ $session->total_cost_credits }} cr · {{ $session->updated_at->diffForHumans() }}</span>
                    </span>
                    @if ($session->messages_count > 0)
                        <span class="badge badge-info">{{ $session->messages_count }}</span>
                    @endif
                </a>
            @endforeach

            <div class="chat-sidebar-footer">
                Quota IA : {{ $quotaStatus['ai']['used'] }}/{{ $quotaStatus['ai']['unlimited'] ? '∞' : $quotaStatus['ai']['quota'] }} ce mois
            </div>
        </aside>

        {{-- Zone principale --}}
        <div class="chat-main">

            {{-- En-tête --}}
            <div class="chat-main-header">
                <div class="chat-context">
                    <span class="chat-context-dot"></span>
                    <strong>Assistant FORMADOC</strong>
                    <span class="chat-context-info">— outils actionnables : pages de garde, reconstruction, recherche web, images</span>
                </div>
                <div class="chat-header-actions">
                    <span class="model-tag">{{ $chatSession->model_used ?: 'modèle du plan' }}</span>
                </div>
            </div>

            {{-- Messages --}}
            <div class="chat-messages" id="chat-messages">
                @forelse ($messages as $message)
                    <div class="message {{ $message->role === 'user' ? 'user' : '' }}">
                        <div class="chat-avatar {{ $message->role === 'assistant' ? 'assistant-avatar' : '' }}">
                            {{ $message->role === 'user' ? strtoupper(substr(auth()->user()->name, 0, 1)) : 'AI' }}
                        </div>
                        <div class="msg-body">
                            <div class="msg-bubble">
                                {!! nl2br(e($message->content)) !!}
                                @if (! empty($message->metadata['tool_turns']) && $message->metadata['tool_turns'] > 0)
                                    <p style="margin-top:.6rem;font-size:.76rem;opacity:.85">
                                        <i data-lucide="wrench" style="width:12px;height:12px;display:inline-block"></i>
                                        {{ $message->metadata['tool_turns'] }} action(s) exécutée(s)
                                    </p>
                                @endif
                            </div>
                            <div class="meta">
                                @if ($message->model_used)
                                    <span class="model-tag">{{ $message->model_used }}</span>
                                @endif
                                @if ($message->cost_credits > 0)
                                    <span class="cost">{{ $message->cost_credits }} crédit(s)</span>
                                @endif
                                <span>{{ $message->created_at->format('H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div style="text-align:center;padding:3rem 1rem;color:var(--color-text-muted)">
                        <i data-lucide="bot" style="width:44px;height:44px;margin:0 auto .8rem;display:block;opacity:.6"></i>
                        <p style="font-size:.95rem">Aucun message. Posez votre première question !</p>
                        <p style="font-size:.78rem;margin-top:.3rem">Exemples : « génère une page de garde », « reconstruis mon document », « cherche des sources sur le changement climatique au Cameroun »</p>
                    </div>
                @endforelse
            </div>

            {{-- Composer --}}
            <form class="chat-composer" action="{{ route('chat.send', $chatSession) }}" method="POST" id="chat-form">
                @csrf
                <div class="composer-box">
                    <textarea name="message" id="chat-input" rows="1" required maxlength="12000"
                              placeholder="Posez une question ou demandez une action…"
                              aria-label="Votre message"></textarea>
                    <div class="composer-tools">
                        <button type="button" class="composer-tool-btn" title="Outils disponibles (actionnables par l'IA)"
                                onclick="document.getElementById('tools-hint').style.display = document.getElementById('tools-hint').style.display === 'none' ? 'flex' : 'none'">
                            <i data-lucide="wrench"></i>
                        </button>
                    </div>
                    <button type="submit" class="composer-send" id="chat-send" aria-label="Envoyer">
                        <i data-lucide="arrow-up"></i>
                    </button>
                </div>

                {{-- Coût estimé avant envoi --}}
                <div class="composer-hint">
                    <span class="chat-cost-preview">
                        <i data-lucide="coins"></i>
                        Coût estimé : <strong>{{ $chatCostEstimate ?? 0 }} crédit(s)</strong>
                        (modèles du plan, ajusté après usage)
                    </span>
                    <span class="hint-keys">
                        <span class="kbd">Ctrl</span><span class="kbd">⏎</span> pour envoyer
                    </span>
                </div>

                {{-- Actions rapides (outils) --}}
                <div class="composer-actions" id="tools-hint">
                    <button type="button" class="chat-action-btn" data-prompt="Génère une page de garde pour mon rapport de stage.">
                        <i data-lucide="book-open"></i> Page de garde
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Reconstruis mon document avec la structure détectée.">
                        <i data-lucide="file-cog"></i> Reconstruction
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Cherche des sources récentes sur le sujet de mon rapport.">
                        <i data-lucide="globe"></i> Recherche web
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Génère une image pour illustrer mon rapport.">
                        <i data-lucide="image"></i> Image
                    </button>
                    @if ($claudeEligible ?? false)
                        <button type="button" class="chat-action-btn" data-prompt="Génère un fichier Excel récapitulatif de mon rapport.">
                            <i data-lucide="table"></i> Fichier Excel (Claude)
                        </button>
                    @endif
                </div>

                <p class="chat-disclaimer">
                    Le coût en crédits est estimé avant l'envoi et confirmé. En cas d'échec, vos crédits sont remboursés.
                    @if ($claudeEligible ?? false)
                        Skills documentaires Claude activés (expérimental) : inclus Standard+, sinon pay-per-use en crédits (×1,5).
                    @endif
                </p>
            </form>
        </div>
    </div>

    <script>
        // Autosize textarea
        const ta = document.getElementById('chat-input');
        ta?.addEventListener('input', () => {
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 140) + 'px';
        });

        // Ctrl+Entrée pour envoyer
        ta?.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('chat-form')?.submit();
            }
        });

        // Actions rapides → remplir le champ
        document.querySelectorAll('.chat-action-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (ta) { ta.value = btn.dataset.prompt; ta.dispatchEvent(new Event('input')); ta.focus(); }
            });
        });

        // Scroll vers le bas au chargement
        const messagesEl = document.getElementById('chat-messages');
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    </script>
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
