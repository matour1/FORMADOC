@extends('layouts.app')

@section('title', 'Conversation — Assistant IA')

@php
    // Modèles réels du plan de l'utilisateur (alignés sur le routage backend)
    $router = app(\App\Services\OpenRouter\ModelRouter::class);
    $planSlug = auth()->user()->currentPlanSlug();
    $chatModel = $router->select('chat_text', $planSlug)['model'];
    $analysisModel = $router->select('document_analysis', $planSlug)['model'];
    $formatModel = $router->select('document_full_format', $planSlug)['model'];
    $imageModel = $router->select('image_generation', $planSlug)['model'];
    $modelShort = fn (string $m) => collect(explode('/', $m))->last();
    $modelName = [
        'deepseek-chat' => 'DeepSeek · Chat',
        'llama-3.1-8b-instruct' => 'Llama · Chat rapide',
        'claude-3.5-sonnet' => 'Claude Sonnet',
        'claude-3-opus' => 'Claude Opus',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
        'gpt-image-1' => 'GPT Image',
        'gpt-image-1-mini' => 'GPT Image mini',
    ][$modelShort($chatModel)] ?? $modelShort($chatModel);
@endphp

@section('content')
    <div class="page-header" style="margin-bottom:1.1rem;">
        <div>
            <span class="eyebrow">Assistant</span>
            <h1>{{ $chatSession->title ?: 'Nouvelle conversation' }}</h1>
            <p>
                @if ($chatSession->model_used)
                    Modèle : <span class="mono">{{ $chatSession->model_used }}</span>
                @endif
                — {{ $chatSession->total_cost_credits }} crédit(s) consommé(s) au total
            </p>
        </div>
        <div style="display:flex;gap:.5rem">
            <a href="{{ route('chat.index') }}" class="btn btn-ghost btn-sm">
                <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Mes conversations
            </a>
        </div>
    </div>

    {{-- Alerte quota IA épuisé (le chat reste accessible, la réponse sera refusée) --}}
    {{-- Q-PAYPERUSE : un utilisateur SANS abonnement payant (plan Gratuit, quota IA
         défini à 0) utilise le chat en pay-per-use via ses crédits : le quota ne
         s'applique PAS à lui, la bannière ne doit pas s'afficher. --}}
    @php
        $quotaStatus = app(\App\Services\Billing\QuotaService::class)->status(auth()->user());
        $aiExhausted = $quotaStatus['ai']['remaining'] <= 0
            && app(\App\Services\Anthropic\ClaudeSkillsService::class)->hasPaidSubscription(auth()->user());
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

            <div class="chat-search-bar" style="margin-bottom:.5rem">
                <i data-lucide="search" style="width:13px;height:13px;color:var(--color-text-muted)"></i>
                <input type="text" id="chatSidebarSearch" placeholder="Rechercher…" style="border:none;background:transparent;font:inherit;color:var(--color-text);outline:none;flex:1">
            </div>

            @foreach ($sessions as $session)
                <div class="chat-item {{ $session->id === $chatSession->id ? 'active' : '' }}"
                     role="button" tabindex="0"
                     onclick="window.location='{{ route('chat.show', $session) }}'"
                     onkeydown="if(event.key==='Enter')window.location='{{ route('chat.show', $session) }}'">
                    <span class="chat-item-icon"><i data-lucide="message-circle"></i></span>
                    <span class="chat-item-main">
                        <span class="chat-item-name">{{ \Illuminate\Support\Str::limit($session->title ?: 'Sans titre', 32) }}</span>
                        <span class="chat-item-sub">{{ $session->messages_count }} msg · {{ $session->updated_at->diffForHumans() }}</span>
                    </span>
                    @if ($session->messages_count > 0)
                        <span class="badge {{ $session->id === $chatSession->id ? 'badge-info' : '' }}">{{ $session->messages_count }}</span>
                    @endif
                    <form action="{{ route('chat.destroy', $session) }}" method="POST"
                          onsubmit="return confirm('Supprimer cette conversation ? Cette action est irréversible.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="chat-item-del" aria-label="Supprimer la conversation">
                            <i data-lucide="trash-2" style="width:13px;height:13px"></i>
                        </button>
                    </form>
                </div>
            @endforeach

            <div class="chat-sidebar-footer mono">Historique conservé 30 jours · Quota IA : {{ $quotaStatus['ai']['used'] }}/{{ $quotaStatus['ai']['unlimited'] ? '∞' : $quotaStatus['ai']['quota'] }}</div>
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
                    {{-- Le mode d'exécution est AUTOMATIQUE (config chat.mode = agent) :
                         les outils sont systématiquement proposés et l'IA les exécute
                         elle-même. Aucun sélecteur n'est exposé à l'utilisateur. --}}

                    {{-- Sélecteur de modèle (routage réel du plan) --}}
                    <div class="chat-model-picker">
                        <button type="button" class="chat-model-select" id="chatModelSelect" aria-haspopup="true" aria-expanded="false">
                            <span class="model-dot"></span>
                            <span id="chatModelLabel">{{ $modelName }}</span>
                            <i data-lucide="chevron-down" style="width:13px;height:13px;flex-shrink:0"></i>
                        </button>
                        <div class="chat-model-menu" id="chatModelMenu" role="menu" aria-label="Choix du modèle">
                            <div class="model-menu-title">Routage IA du plan {{ ucfirst($planSlug) }}</div>
                            <div class="model-menu-item active" data-model="chat_text" role="menuitemradio">
                                <span class="model-menu-icon"><i data-lucide="message-circle" style="width:15px;height:15px"></i></span>
                                <span class="model-menu-info">
                                    <span class="model-menu-name">Chat · {{ $modelShort($chatModel) }} <span class="badge badge-info">Défaut</span></span>
                                    <span class="model-menu-desc">Dialogue rapide et économique, idéal pour discuter.</span>
                                </span>
                                <i data-lucide="check" class="model-menu-check" style="width:14px;height:14px"></i>
                            </div>
                            <div class="model-menu-item" data-model="document_analysis" role="menuitemradio">
                                <span class="model-menu-icon"><i data-lucide="search" style="width:15px;height:15px"></i></span>
                                <span class="model-menu-info">
                                    <span class="model-menu-name">Analyse · {{ $modelShort($analysisModel) }}</span>
                                    <span class="model-menu-desc">Compréhension fine des documents et levée d'ambiguïtés.</span>
                                </span>
                            </div>
                            <div class="model-menu-item" data-model="document_full_format" role="menuitemradio">
                                <span class="model-menu-icon"><i data-lucide="align-left" style="width:15px;height:15px"></i></span>
                                <span class="model-menu-info">
                                    <span class="model-menu-name">Mise en forme · {{ $modelShort($formatModel) }}</span>
                                    <span class="model-menu-desc">Mise en forme complète haute qualité de documents.</span>
                                </span>
                            </div>
                            <div class="model-menu-item" data-model="image_generation" role="menuitemradio">
                                <span class="model-menu-icon"><i data-lucide="image" style="width:15px;height:15px"></i></span>
                                <span class="model-menu-info">
                                    <span class="model-menu-name">Image · {{ $modelShort($imageModel) }}</span>
                                    <span class="model-menu-desc">Génération d'images, illustrations et couvertures.</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="chat-attach-btn" id="chatSearchToggleBtn" aria-label="Rechercher dans les messages" title="Rechercher dans les messages">
                        <i data-lucide="search" style="width:16px;height:16px"></i>
                    </button>
                </div>
            </div>

            {{-- Barre de recherche dans les messages --}}
            <div class="chat-search-bar" id="chatSearchBar" style="display:none;">
                <i data-lucide="search" style="width:14px;height:14px;color:var(--color-text-muted)"></i>
                <input type="text" id="chatSearchInput" placeholder="Rechercher dans les messages…" autocomplete="off">
                <button id="chatSearchClear" aria-label="Fermer la recherche">&times;</button>
            </div>

            {{-- Messages --}}
            <div class="chat-messages" id="chat-messages">
                @php
                    $lastDay = null;
                @endphp
                @forelse ($messages as $message)
                    @php
                        $day = $message->created_at->format('Y-m-d');
                    @endphp
                    @if ($day !== $lastDay)
                        <div class="chat-date-divider">
                            {{ $message->created_at->isToday() ? 'Aujourd\'hui' : ($message->created_at->isYesterday() ? 'Hier' : $message->created_at->isoFormat('D MMM YYYY')) }}
                        </div>
                        @php $lastDay = $day; @endphp
                    @endif

                    <div class="message {{ $message->role === 'user' ? 'user' : '' }}" data-msg-id="{{ $message->id }}">
                        <div class="chat-avatar {{ $message->role === 'assistant' ? 'assistant-avatar' : '' }}">
                            @if ($message->role === 'user')
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            @else
                                <i data-lucide="sparkles" style="width:16px;height:16px"></i>
                            @endif
                        </div>
                        <div class="msg-body">
                            <div class="msg-bubble">
                            {!! nl2br(e($message->content)) !!}
                                @if (! empty($message->metadata['tool_turns']) && $message->metadata['tool_turns'] > 0)
                                    {{-- Q-MASQUAGE : badge discret, sans détail brut des appels d'outil --}}
                                    <p style="margin-top:.6rem;font-size:.76rem;opacity:.85">
                                        <i data-lucide="wrench" style="width:12px;height:12px;display:inline-block"></i>
                                        Action effectuée
                                    </p>
                                @endif
                                @if (! empty($message->metadata['generated_files']))
                                    {{-- P0-4 : fichiers générés par l'IA (document édité, PDF, Word, image…) --}}
                                    <div class="msg-attachments" style="margin-top:.6rem;display:flex;flex-direction:column;gap:.35rem">
                                        @foreach ($message->metadata['generated_files'] as $gfile)
                                            @php
                                                $gname = basename($gfile);
                                            @endphp
                                            <a href="{{ route('chat.files.download', ['file' => $gfile]) }}"
                                               class="attach-chip" style="display:inline-flex;align-items:center;gap:.4rem;text-decoration:none"
                                               download="{{ $gname }}" title="Télécharger {{ $gname }}">
                                                <i data-lucide="file-down" style="width:13px;height:13px"></i>
                                                {{ $gname }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                                @if (! empty($message->metadata['attachments']))
                                    <div class="msg-attachments" style="margin-top:.6rem;display:flex;flex-direction:column;gap:.35rem">
                                        @foreach ($message->metadata['attachments'] as $att)
                                            <a href="{{ route('chat.files.download', ['file' => $att['path']]) }}"
                                               class="attach-chip" style="display:inline-flex;align-items:center;gap:.4rem;text-decoration:none"
                                               download="{{ $att['name'] }}" title="Télécharger {{ $att['name'] }}">
                                                <i data-lucide="paperclip" style="width:13px;height:13px"></i>
                                                {{ $att['name'] }}
                                                @if (! empty($att['size']))
                                                    <span style="opacity:.75">({{ number_format($att['size'] / 1024, 0, ',', ' ') }} Ko)</span>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="meta">
                                <span class="msg-time">{{ $message->created_at->format('H:i') }}</span>
                                @if ($message->model_used)
                                    <span class="model-tag">{{ $message->model_used }}</span>
                                @endif
                                {{-- Q-TEMPS : durée de traitement (affichée pour les traitements longs) --}}
                                @if (! empty($message->metadata['duration_ms']) && $message->metadata['duration_ms'] >= 3000)
                                    <span class="model-tag" title="Temps de traitement">
                                        <i data-lucide="timer" style="width:11px;height:11px;display:inline-block;vertical-align:-1px"></i>
                                        {{ \Illuminate\Support\Str::of($message->metadata['duration_ms'] / 1000)->limit(5, '') }} s
                                    </span>
                                @endif
                                {{-- Q-FALLBACK : provider utilisé (transparence) --}}
                                @if (! empty($message->metadata['provider']) && $message->metadata['provider'] !== 'openrouter')
                                    <span class="model-tag" style="opacity:.8" title="Réponse fournie via le fournisseur de secours">
                                        <i data-lucide="server" style="width:11px;height:11px;display:inline-block;vertical-align:-1px"></i>
                                        secours
                                    </span>
                                @endif
                                @if ($message->cost_credits > 0)
                                    <span class="cost">{{ $message->cost_credits }} crédit(s)</span>
                                @endif
                                <button class="msg-action-btn" data-msg-action="copy" aria-label="Copier ce message" title="Copier">
                                    <i data-lucide="copy" style="width:12px;height:12px"></i>
                                </button>
                                @if ($message->role === 'assistant')
                                    <button class="msg-reaction-btn" data-reaction="👍" aria-label="Réagir 👍">👍</button>
                                    <button class="msg-reaction-btn" data-reaction="❤️" aria-label="Réagir ❤️">❤️</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="empty-state" style="text-align:center;padding:3rem 1rem;color:var(--color-text-muted)">
                        <i data-lucide="bot" style="width:44px;height:44px;margin:0 auto .8rem;display:block;opacity:.6"></i>
                        <p style="font-size:.95rem">Aucun message. Pose ta première question !</p>
                        <p style="font-size:.78rem;margin-top:.3rem">Exemples : « génère une page de garde », « reconstruis mon document », « cherche des sources sur le changement climatique au Cameroun »</p>
                    </div>
                @endforelse

                {{-- Chips de suggestions (quand la conversation est vide) --}}
                @if ($messages->isEmpty())
                    <div class="suggest-chips" id="chatSuggest">
                        <button type="button" class="suggest-chip" data-prompt="Génère une page de garde pour mon rapport de stage.">Créer une page de garde</button>
                        <button type="button" class="suggest-chip" data-prompt="Reconstruis mon document avec la structure détectée.">Reconstruire un document</button>
                        <button type="button" class="suggest-chip" data-prompt="Cherche des sources récentes sur le changement climatique au Cameroun.">Recherche web</button>
                        <button type="button" class="suggest-chip" data-prompt="Génère une image pour illustrer mon rapport.">Générer une image</button>
                    </div>
                @endif

                {{-- Indicateur de pensée (Claude-style) --}}
                <div class="thinking-indicator" id="typingIndicator" style="display:none;">
                    <span class="thinking-avatar"><i data-lucide="sparkles" style="width:14px;height:14px"></i></span>
                    <span class="thinking-dots"><span></span><span></span><span></span></span>
                    <span class="thinking-label">L'assistant réfléchit…</span>
                </div>
            </div>

            {{-- Composer --}}
            <form class="chat-composer" action="{{ route('chat.send', $chatSession) }}" method="POST" id="chat-form" enctype="multipart/form-data">
                @csrf
                {{-- Le mode d'exécution est automatique (config chat.mode) :
                     aucun champ mode n'est envoyé — le backend applique
                     tool_choice: required quand le mode est agent. --}}
                <div class="composer-attachments" id="chatAttachments" style="display:none;"></div>

                <div class="composer-box">
                    <textarea name="message" id="chat-input" rows="1" required maxlength="12000"
                              placeholder="Pose ta question ou demande une action…"
                              aria-label="Votre message"></textarea>
                    <div class="composer-tools">
                        <div class="composer-tools-wrap">
                            <button type="button" class="composer-tool-btn" id="chatEmojiBtn" aria-label="Ajouter un emoji" title="Emoji">
                                <i data-lucide="smile" style="width:17px;height:17px"></i>
                            </button>
                            <div class="emoji-popover" id="emojiPopover" style="display:none;">
                                <button type="button" data-emoji="😊">😊</button><button type="button" data-emoji="😂">😂</button><button type="button" data-emoji="❤️">❤️</button><button type="button" data-emoji="👍">👍</button>
                                <button type="button" data-emoji="🙏">🙏</button><button type="button" data-emoji="🔥">🔥</button><button type="button" data-emoji="💡">💡</button><button type="button" data-emoji="📌">📌</button>
                            </div>
                            <button type="button" class="composer-tool-btn" id="chatAttachIconBtn" aria-label="Joindre un fichier" title="Joindre un fichier">
                                <i data-lucide="paperclip" style="width:17px;height:17px"></i>
                            </button>
                            <input type="file" id="chatFileInput" name="attachments[]" multiple hidden accept=".docx,.pdf,.txt,.md,.xlsx,.pptx">
                        </div>
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
                    Le coût en crédits est estimé avant l'envoi et ajusté après usage. En cas d'échec, vos crédits sont remboursés.
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

        // Indicateur « L'assistant réfléchit… » + protection pendant l'envoi.
        // IMPORTANT : on utilise readOnly (et non disabled) pour le textarea :
        // un champ disabled n'est PAS envoyé avec le formulaire → Laravel
        // renverrait « The message field is required ».
        const chatForm = document.getElementById('chat-form');
        const typingIndicator = document.getElementById('typingIndicator');
        const chatSend = document.getElementById('chat-send');
        if (chatForm) {
            chatForm.addEventListener('submit', () => {
                const input = document.getElementById('chat-input');
                if (!input || !input.value.trim()) return; // Laisse le required gérer
                if (typingIndicator) typingIndicator.style.display = 'flex';
                if (chatSend) { chatSend.disabled = true; chatSend.setAttribute('aria-busy', 'true'); }
                if (input) input.readOnly = true;
            });
        }

        // Actions rapides → remplir le champ
        document.querySelectorAll('.chat-action-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (ta) { ta.value = btn.dataset.prompt; ta.dispatchEvent(new Event('input')); ta.focus(); }
            });
        });

        // Chips de suggestions → remplir le champ
        document.querySelectorAll('.suggest-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                if (ta) { ta.value = chip.dataset.prompt; ta.dispatchEvent(new Event('input')); ta.focus(); }
            });
        });

        // Sélecteur de modèle (routage : le backend choisit le modèle, ici on guide l'utilisateur)
        const modelSelect = document.getElementById('chatModelSelect');
        const modelMenu = document.getElementById('chatModelMenu');
        if (modelSelect && modelMenu) {
            modelSelect.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = modelMenu.classList.toggle('open');
                modelSelect.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', (e) => {
                if (!modelMenu.contains(e.target)) {
                    modelMenu.classList.remove('open');
                    modelSelect.setAttribute('aria-expanded', 'false');
                }
            });
            modelMenu.querySelectorAll('.model-menu-item').forEach(item => {
                item.addEventListener('click', () => {
                    modelMenu.querySelectorAll('.model-menu-item').forEach(x => {
                        x.classList.remove('active');
                        x.querySelector('.model-menu-check')?.remove();
                    });
                    item.classList.add('active');
                    item.insertAdjacentHTML('beforeend', '<i data-lucide="check" class="model-menu-check" style="width:14px;height:14px;color:var(--color-primary);flex-shrink:0;margin-top:.15rem"></i>');
                    if (window.lucide) lucide.createIcons();
                    // Indication : le routage backend choisit le modèle selon le type de tâche.
                    const label = document.getElementById('chatModelLabel');
                    if (label && item.dataset.model) {
                        label.textContent = item.querySelector('.model-menu-name')?.childNodes[0]?.textContent?.trim() || label.textContent;
                    }
                    modelMenu.classList.remove('open');
                    modelSelect.setAttribute('aria-expanded', 'false');
                });
            });
        }

        // Émojis
        const emojiBtn = document.getElementById('chatEmojiBtn');
        const emojiPop = document.getElementById('emojiPopover');
        if (emojiBtn && emojiPop) {
            emojiBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                emojiPop.style.display = emojiPop.style.display === 'none' ? 'grid' : 'none';
            });
            document.addEventListener('click', (e) => {
                if (!emojiPop.contains(e.target)) emojiPop.style.display = 'none';
            });
            emojiPop.querySelectorAll('[data-emoji]').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (ta) { ta.value += btn.dataset.emoji; ta.dispatchEvent(new Event('input')); ta.focus(); }
                    emojiPop.style.display = 'none';
                });
            });
        }

        // Copier un message
        document.querySelectorAll('.msg-action-btn[data-msg-action="copy"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const bubble = btn.closest('.message')?.querySelector('.msg-bubble');
                if (bubble) {
                    try {
                        await navigator.clipboard.writeText(bubble.textContent.trim());
                        const icon = btn.querySelector('i');
                        if (icon) { icon.setAttribute('data-lucide', 'check'); if (window.lucide) lucide.createIcons(); setTimeout(() => { icon.setAttribute('data-lucide', 'copy'); if (window.lucide) lucide.createIcons(); }, 1500); }
                    } catch (e) { /* clipboard indisponible */ }
                }
            });
        });

        // Réactions (local, stockées dans la page)
        document.querySelectorAll('.msg-reaction-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.classList.toggle('reacted');
            });
        });

        // Pièces jointes (affichage local ; upload réel géré par le backend)
        // Q-PJ : le coût estimé affiché inclut le coût des pièces jointes
        // sélectionnées (coût fixe par fichier, cf. config/chat.php).
        const fileInput = document.getElementById('chatFileInput');
        const attachBtn = document.getElementById('chatAttachIconBtn');
        const attachments = document.getElementById('chatAttachments');
        const costPreview = document.querySelector('.chat-cost-preview strong');
        const baseCost = {{ (int) ($chatCostEstimate ?? 0) }};
        const attachmentCost = {{ (int) ($attachmentCostCredits ?? 1) }};
        if (fileInput && attachBtn && attachments) {
            const updateCostPreview = () => {
                if (!costPreview) return;
                const total = baseCost + (fileInput.files.length * attachmentCost);
                costPreview.textContent = total + ' crédit(s)';
            };
            attachBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', () => {
                attachments.innerHTML = '';
                [...fileInput.files].forEach(f => {
                    const chip = document.createElement('span');
                    chip.className = 'attach-chip';
                    chip.textContent = f.name + ' (' + (f.size / 1024).toFixed(0) + ' Ko)';
                    attachments.appendChild(chip);
                });
                attachments.style.display = fileInput.files.length ? 'flex' : 'none';
                updateCostPreview();
            });
        }

        // Recherche dans les messages
        const searchToggle = document.getElementById('chatSearchToggleBtn');
        const searchBar = document.getElementById('chatSearchBar');
        const searchInput = document.getElementById('chatSearchInput');
        const searchClear = document.getElementById('chatSearchClear');
        if (searchToggle && searchBar && searchInput) {
            searchToggle.addEventListener('click', () => {
                searchBar.style.display = searchBar.style.display === 'none' ? 'flex' : 'none';
                if (searchBar.style.display === 'flex') searchInput.focus();
            });
            searchClear?.addEventListener('click', () => {
                searchInput.value = '';
                document.querySelectorAll('#chat-messages .message').forEach(m => m.style.display = '');
                searchBar.style.display = 'none';
            });
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('#chat-messages .message').forEach(m => {
                    const hay = (m.textContent || '').toLowerCase();
                    m.style.display = q === '' || hay.includes(q) ? '' : 'none';
                });
            });
        }

        // Recherche dans la sidebar des conversations
        const sidebarSearch = document.getElementById('chatSidebarSearch');
        if (sidebarSearch) {
            sidebarSearch.addEventListener('input', () => {
                const q = sidebarSearch.value.trim().toLowerCase();
                document.querySelectorAll('.chat-sidebar .chat-item').forEach(item => {
                    const hay = (item.textContent || '').toLowerCase();
                    item.style.display = q === '' || hay.includes(q) ? '' : 'none';
                });
            });
        }

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
