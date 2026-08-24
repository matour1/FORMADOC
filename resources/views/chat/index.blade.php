@extends('layouts.app')

@section('title', 'Assistant IA')

@section('content')
    <div class="page-header" style="margin-bottom:1.1rem;">
        <div>
            <span class="eyebrow">Assistant</span>
            <h1>Assistant IA</h1>
            <p>Analyse, rédaction et actions sur vos documents.</p>
        </div>
        <div class="credits-badge {{ auth()->user()->credits_balance < 100 ? 'low' : '' }}" title="Solde de crédits — 1 crédit = 1 FCFA">
            <i data-lucide="coins" style="width:14px;height:14px"></i>
            {{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }} crédits
        </div>
    </div>

    <div class="chat-layout">

        {{-- Confirmation de coût AVANT envoi (exigence A) --}}
        @if ($pendingCost = session('pending_cost'))
            <div class="banner banner-warning" style="margin-bottom:1rem">
                <i data-lucide="info"></i>
                <div style="flex:1">
                    <strong>Coût estimé : {{ is_array($pendingCost) ? ($pendingCost['credits'] ?? '?') : '?' }} crédit(s)</strong>
                    <div style="font-size:.82rem">Estimation avant exécution — ajustement automatique après usage.</div>
                </div>
                <form action="{{ route('chat.send') }}" method="POST" style="display:inline-flex;gap:.5rem">
                    @csrf
                    <input type="hidden" name="message" value="{{ is_array($pendingCost) ? ($pendingCost['message'] ?? '') : '' }}">
                    <input type="hidden" name="confirm_cost" value="1">
                    <button type="submit" class="btn btn-primary btn-sm">Confirmer et envoyer</button>
                    <a href="{{ route('chat.index') }}" class="btn btn-ghost btn-sm">Annuler</a>
                </form>
            </div>
        @endif

        {{-- Sidebar des conversations --}}
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <span class="chat-sidebar-title">Conversations</span>
                <span class="chat-sidebar-title mono">{{ $sessions->count() }}</span>
            </div>

            <div class="chat-search-bar">
                <i data-lucide="search" style="width:14px;height:14px;color:var(--color-text-muted);flex-shrink:0"></i>
                <input type="text" id="chatSidebarSearch" placeholder="Rechercher une conversation…" aria-label="Rechercher une conversation" />
            </div>

            <a href="{{ route('chat.index') }}" class="btn btn-secondary btn-sm btn-block" style="margin-bottom:.7rem;margin-top:.55rem;">
                <i data-lucide="square-pen" style="width:14px;height:14px"></i> Nouvelle conversation
            </a>

            <div id="chatSessionList">
            @forelse ($sessions as $session)
                <div class="chat-item {{ (request()->routeIs('chat.show') && optional(request()->route('chatSession'))->id === $session->id) ? 'active' : '' }}" role="button" tabindex="0"
                     onclick="window.location='{{ route('chat.show', $session) }}'"
                     onkeydown="if(event.key==='Enter')window.location='{{ route('chat.show', $session) }}'">
                    <span class="chat-item-icon"><i data-lucide="message-circle"></i></span>
                    <span class="chat-item-main">
                        <span class="chat-item-name">{{ \Illuminate\Support\Str::limit($session->title ?: 'Sans titre', 32) }}</span>
                        <span class="chat-item-sub">{{ $session->messages_count }} msg · {{ $session->updated_at->diffForHumans() }}</span>
                    </span>
                    @if ($session->messages_count > 0)
                        <span class="badge">{{ $session->messages_count }}</span>
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
            @empty
                <div class="chat-empty" id="chatEmptyState" style="text-align:center;padding:2rem 1rem;color:var(--color-text-muted);font-size:.85rem">
                    Aucune conversation pour le moment.
                </div>
            @endforelse
            </div>
            <div class="chat-empty" id="chatSearchEmpty" style="display:none;text-align:center;padding:1.5rem 1rem;color:var(--color-text-muted);font-size:.82rem">
                Aucune conversation ne correspond.
            </div>

            <div class="chat-sidebar-footer mono">Historique conservé 30 jours · <span id="chatQuotaFooter"></span></div>
        </aside>

        {{-- Zone principale : nouvelle conversation --}}
        <div class="chat-main">
            <div class="chat-main-header">
                <div class="chat-context">
                    <span class="chat-context-dot"></span>
                    <strong>Nouvelle conversation</strong>
                    <span class="chat-context-info">— posez une question ou demandez une action</span>
                </div>
                <div class="chat-header-actions">
                    <div class="chat-model-picker" id="chatModelPicker">
                        <button type="button" class="btn btn-ghost btn-sm" id="chatModelSelect" aria-expanded="false" aria-haspopup="true">
                            <i data-lucide="cpu" style="width:14px;height:14px"></i>
                            <span id="chatModelLabel">{{ $chatModelName ?? 'Routage automatique' }}</span>
                            <i data-lucide="chevron-down" style="width:13px;height:13px"></i>
                        </button>
                        <div class="chat-model-menu" id="chatModelMenu">
                            <div class="model-menu-title">Modèles disponibles</div>
                            <div class="model-menu-item active" data-model="auto">
                                <span class="model-menu-icon"><i data-lucide="sparkles"></i></span>
                                <span class="model-menu-info">
                                    <span class="model-menu-name">Auto <span class="badge badge-info">Défaut</span></span>
                                    <span class="model-menu-desc">Routage optimal selon la tâche et votre plan.</span>
                                </span>
                                <i data-lucide="check" class="model-menu-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages" style="align-content:center">
                <div class="empty-state" style="text-align:center;padding:2rem 1rem;color:var(--color-text-muted)">
                    <i data-lucide="sparkles" style="width:44px;height:44px;margin:0 auto .8rem;display:block;opacity:.6"></i>
                    <p style="font-size:1.05rem;font-weight:600;color:var(--color-text)">Comment puis-je vous aider ?</p>
                    <p style="font-size:.82rem;margin-top:.3rem">L'assistant peut analyser, mettre en forme et agir sur vos documents.</p>
                </div>

                <div class="suggest-chips" id="chatSuggest">
                    <button type="button" class="suggest-chip" data-prompt="Génère une page de garde pour mon rapport de stage.">Créer une page de garde</button>
                    <button type="button" class="suggest-chip" data-prompt="Explique-moi comment structurer un rapport de stage de 30 pages.">Structurer un rapport</button>
                    <button type="button" class="suggest-chip" data-prompt="Cherche des sources récentes sur le changement climatique au Cameroun.">Recherche web</button>
                    <button type="button" class="suggest-chip" data-prompt="Génère une image pour illustrer mon rapport.">Générer une image</button>
                </div>

                {{-- Indicateur de pensée (affiché pendant l'envoi) --}}
                <div class="thinking-indicator" id="typingIndicator" style="display:none;" aria-live="polite">
                    <span class="thinking-avatar"><i data-lucide="sparkles" style="width:14px;height:14px"></i></span>
                    <span class="thinking-dots"><span></span><span></span><span></span></span>
                    <span class="thinking-label">L'assistant réfléchit…</span>
                </div>
            </div>

            {{-- Composer --}}
            <form class="chat-composer" action="{{ route('chat.send') }}" method="POST" id="chat-form">
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

                <div class="composer-hint">
                    <span class="chat-cost-preview">
                        <i data-lucide="coins"></i>
                        Coût estimé : <strong>{{ $estimatedCredits ?? 0 }} crédit(s)</strong> — confirmé avant envoi
                    </span>
                    <span class="hint-keys">
                        <span class="kbd">Ctrl</span><span class="kbd">⏎</span> pour envoyer
                    </span>
                </div>

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
                    Une nouvelle conversation sera créée. Chaque message est facturé à son coût réel (ajustement automatique).
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

        // Indicateur « L'assistant réfléchit… » + désactivation pendant l'envoi
        const chatForm = document.getElementById('chat-form');
        const typingIndicator = document.getElementById('typingIndicator');
        const chatSend = document.getElementById('chat-send');
        if (chatForm) {
            chatForm.addEventListener('submit', () => {
                const input = document.getElementById('chat-input');
                if (!input || !input.value.trim()) return; // Laisse le required gérer
                if (typingIndicator) typingIndicator.style.display = 'flex';
                if (chatSend) { chatSend.disabled = true; chatSend.setAttribute('aria-busy', 'true'); }
                if (input) input.disabled = true;
            });
        }

        // Actions rapides et chips → remplir le champ
        document.querySelectorAll('.chat-action-btn, .suggest-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                if (ta) { ta.value = btn.dataset.prompt; ta.dispatchEvent(new Event('input')); ta.focus(); }
            });
        });

        // Recherche dans la sidebar des conversations
        const sidebarSearch = document.getElementById('chatSidebarSearch');
        const sessionList = document.getElementById('chatSessionList');
        const emptyState = document.getElementById('chatEmptyState');
        const searchEmpty = document.getElementById('chatSearchEmpty');
        sidebarSearch?.addEventListener('input', () => {
            const q = sidebarSearch.value.trim().toLowerCase();
            let visible = 0;
            document.querySelectorAll('#chatSessionList .chat-item').forEach(item => {
                const match = item.textContent.toLowerCase().includes(q);
                item.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (searchEmpty) searchEmpty.style.display = (q && visible === 0) ? 'block' : 'none';
            if (emptyState) emptyState.style.display = (!q && visible === 0) ? '' : 'none';
        });

        // Picker de modèle (informations — routage automatique côté serveur)
        const modelSelect = document.getElementById('chatModelSelect');
        const modelMenu = document.getElementById('chatModelMenu');
        if (modelSelect && modelMenu) {
            const closeModelMenu = () => {
                modelMenu.classList.remove('open');
                modelSelect.setAttribute('aria-expanded', 'false');
            };
            const openModelMenu = () => {
                modelMenu.classList.add('open');
                modelSelect.setAttribute('aria-expanded', 'true');
            };
            modelSelect.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = modelMenu.classList.toggle('open');
                modelSelect.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', (e) => {
                if (!modelMenu.contains(e.target)) closeModelMenu();
            });
            // Clavier (P2 audit UI/UX) : Échap ferme, flèches parcourent, Entrée/Space choisit
            modelSelect.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openModelMenu();
                    const items = [...modelMenu.querySelectorAll('.model-menu-item')];
                    const activeIdx = items.findIndex(i => i.classList.contains('active'));
                    const nextIdx = e.key === 'ArrowDown' ? Math.min(activeIdx + 1, items.length - 1)
                        : (e.key === 'ArrowUp' ? Math.max(activeIdx - 1, 0) : activeIdx);
                    items.forEach(i => i.setAttribute('tabindex', '-1'));
                    items[nextIdx]?.setAttribute('tabindex', '0');
                    items[nextIdx]?.focus();
                }
            });
            modelMenu.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') { e.preventDefault(); closeModelMenu(); modelSelect.focus(); return; }
                const items = [...modelMenu.querySelectorAll('.model-menu-item')];
                const idx = items.findIndex(i => i.getAttribute('tabindex') === '0');
                if (e.key === 'ArrowDown') { e.preventDefault(); const n = (idx + 1) % items.length; items.forEach(i => i.setAttribute('tabindex', '-1')); items[n].setAttribute('tabindex', '0'); items[n].focus(); }
                if (e.key === 'ArrowUp') { e.preventDefault(); const n = (idx - 1 + items.length) % items.length; items.forEach(i => i.setAttribute('tabindex', '-1')); items[n].setAttribute('tabindex', '0'); items[n].focus(); }
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const item = items[idx];
                    if (item) item.click();
                }
            });
            modelMenu.querySelectorAll('.model-menu-item').forEach(item => {
                item.addEventListener('click', () => {
                    modelMenu.querySelectorAll('.model-menu-item').forEach(i => i.classList.remove('active'));
                    item.classList.add('active');
                    const label = item.querySelector('.model-menu-name');
                    if (label) {
                        document.getElementById('chatModelLabel').textContent = label.childNodes[0].textContent.trim();
                    }
                    closeModelMenu();
                });
            });
        }
    </script>
@endsection

