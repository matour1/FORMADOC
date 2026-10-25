@extends('layouts.app')

@section('title', 'Assistant IA')

@section('content')
    <div class="page-header" style="margin-bottom:1.1rem;">
        <div>
            <span class="eyebrow">Assistant & Rédaction</span>
            <h1>Assistant IA FORMADOC</h1>
            <p>Analyse de conformité, correction académique et mise en forme intelligente.</p>
        </div>
        <div class="credits-badge {{ auth()->user()->credits_balance < 100 ? 'low' : '' }}" title="Solde de crédits — 1 crédit = 1 FCFA" style="border-radius:var(--radius-full);padding:.4rem .85rem;box-shadow:var(--shadow-1);">
            <i data-lucide="coins" style="width:15px;height:15px"></i>
            <strong>{{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }}</strong> crédits
        </div>
    </div>

    <div class="chat-layout">
        {{-- Sidebar des conversations --}}
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <span class="chat-sidebar-title">Historique</span>
                <span class="badge badge-info mono">{{ $sessions->count() }}</span>
            </div>

            <div class="chat-search-bar" style="background:var(--color-surface-2);border-radius:var(--radius-sm);padding:.4rem .65rem;display:flex;align-items:center;gap:.5rem;border:1px solid var(--color-border);margin-bottom:.5rem;">
                <i data-lucide="search" style="width:14px;height:14px;color:var(--color-text-muted);flex-shrink:0"></i>
                <input type="text" id="chatSidebarSearch" placeholder="Rechercher une conversation…" aria-label="Rechercher une conversation" style="border:none;background:transparent;outline:none;font-size:.82rem;color:var(--color-text);width:100%;" />
            </div>

            <a href="{{ route('chat.index') }}" class="btn btn-primary btn-sm btn-block" style="margin-bottom:.75rem;margin-top:.2rem;border-radius:var(--radius-sm);justify-content:center;gap:.5rem;">
                <i data-lucide="plus-circle" style="width:15px;height:15px"></i> Nouvelle conversation
            </a>

            <div id="chatSessionList" style="display:flex;flex-direction:column;gap:.25rem;">
            @forelse ($sessions as $session)
                <div class="chat-item {{ (request()->routeIs('chat.show') && optional(request()->route('chatSession'))->id === $session->id) ? 'active' : '' }}" role="button" tabindex="0"
                     onclick="window.location='{{ route('chat.show', $session) }}'"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('chat.show', $session) }}';}">
                    <span class="chat-item-icon"><i data-lucide="message-square"></i></span>
                    <span class="chat-item-main">
                        <span class="chat-item-name" style="font-weight:600;">{{ \Illuminate\Support\Str::limit($session->title ?: 'Conversation sans titre', 30) }}</span>
                        <span class="chat-item-sub">{{ $session->messages_count }} msg · {{ $session->updated_at->diffForHumans() }}</span>
                    </span>
                    @if ($session->messages_count > 0)
                        <span class="badge" style="font-size:.68rem;">{{ $session->messages_count }}</span>
                    @endif
                    <form action="{{ route('chat.destroy', $session) }}" method="POST"
                          onsubmit="return confirm('Supprimer cette conversation ? Cette action est irréversible.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="chat-item-del" aria-label="Supprimer la conversation" title="Supprimer">
                            <i data-lucide="trash-2" style="width:13px;height:13px"></i>
                        </button>
                    </form>
                </div>
            @empty
                <div class="chat-empty" id="chatEmptyState">
                    <i data-lucide="message-circle-off" style="width:28px;height:28px;margin:0 auto .5rem;display:block;opacity:.4"></i>
                    Aucune conversation pour le moment.
                </div>
            @endforelse
            </div>
            <div class="chat-empty compact" id="chatSearchEmpty" style="display:none">
                Aucune conversation ne correspond.
            </div>

            <div class="chat-sidebar-footer mono">
                Historique privé · Suppression manuelle à la demande
            </div>
        </aside>

        {{-- Zone principale : nouvelle conversation --}}
        <div class="chat-main">
            <div class="chat-main-header">
                <div class="chat-context">
                    <span class="chat-context-dot"></span>
                    <strong>Nouvelle session</strong>
                    <span class="chat-context-info">— Posez votre question ou soumettez une tâche</span>
                </div>
                <div class="chat-header-actions">
                    <span class="chat-model-note" title="Le modèle est sélectionné dynamiquement pour un résultat optimal conforme aux normes universitaires.">
                        <i data-lucide="sparkles" style="width:13px;height:13px;color:var(--color-accent)"></i>
                        Routage IA intelligent
                    </span>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages" style="align-content:center;justify-content:center;display:flex;flex-direction:column;gap:1.5rem;">
                <div class="empty-state" style="text-align:center;padding:1.5rem 1rem 0;color:var(--color-text-muted);max-width:540px;margin:0 auto;">
                    <div style="width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg, var(--color-primary-tint), var(--color-accent-tint));color:var(--color-primary);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;box-shadow:var(--shadow-1);border:1px solid var(--color-border);">
                        <i data-lucide="sparkles" style="width:28px;height:28px;color:var(--color-accent)"></i>
                    </div>
                    <h2 style="font-size:1.25rem;font-weight:700;color:var(--color-text);margin-bottom:.4rem;font-family:var(--font-display);">Comment puis-je vous aider aujourd'hui ?</h2>
                    <p style="font-size:.86rem;line-height:1.5;color:var(--color-text-secondary);margin:0;">
                        L'assistant FORMADOC vérifie la conformité académique de votre rapport (SOPAL, CAMES, Masters), rédige vos pièces liminaires et structure votre document.
                    </p>
                </div>

                {{-- Grille de suggestions élégante --}}
                <div class="suggest-chips" id="chatSuggest" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:.75rem;max-width:680px;margin:0 auto;width:100%;padding:0 1rem;">
                    <button type="button" class="suggest-chip" data-prompt="Analyse la structure de ma pièce jointe et dis-moi si elle respecte la norme académique (pièces liminaires en chiffres romains, corps en chiffres arabes, bibliographie, table des matières)." style="text-align:left;padding:.75rem 1rem;border-radius:var(--radius);display:flex;flex-direction:column;gap:.3rem;">
                        <span style="font-weight:600;font-size:.85rem;color:var(--color-text);display:flex;align-items:center;gap:.4rem;">
                            <i data-lucide="file-check-2" style="width:14px;height:14px;color:var(--color-accent)"></i> Conformité académique
                        </span>
                        <span style="font-size:.74rem;color:var(--color-text-muted);line-height:1.35;">Vérification des pièces liminaires, pagination et hiérarchie.</span>
                    </button>

                    <button type="button" class="suggest-chip" data-prompt="Rédige une Dédicace, des Remerciements et un Avant-propos académiques pour mon rapport de stage." style="text-align:left;padding:.75rem 1rem;border-radius:var(--radius);display:flex;flex-direction:column;gap:.3rem;">
                        <span style="font-weight:600;font-size:.85rem;color:var(--color-text);display:flex;align-items:center;gap:.4rem;">
                            <i data-lucide="feather" style="width:14px;height:14px;color:var(--color-secondary)"></i> Dédicace & Remerciements
                        </span>
                        <span style="font-size:.74rem;color:var(--color-text-muted);line-height:1.35;">Rédaction soignée selon les formules universitaires en vigueur.</span>
                    </button>

                    <button type="button" class="suggest-chip" data-prompt="Rédige un Résumé en français et son Abstract en anglais (avec mots-clés / keywords) pour mon rapport de stage." style="text-align:left;padding:.75rem 1rem;border-radius:var(--radius);display:flex;flex-direction:column;gap:.3rem;">
                        <span style="font-weight:600;font-size:.85rem;color:var(--color-text);display:flex;align-items:center;gap:.4rem;">
                            <i data-lucide="languages" style="width:14px;height:14px;color:var(--color-warning)"></i> Résumé & Abstract
                        </span>
                        <span style="font-size:.74rem;color:var(--color-text-muted);line-height:1.35;">Synthèse bilingue percutante avec liste de mots-clés normalisés.</span>
                    </button>

                    <button type="button" class="suggest-chip" data-prompt="Propose un plan détaillé (Première Partie, Deuxième Partie, Chapitres I à IV, Introduction et Conclusion générale) pour mon rapport de stage." style="text-align:left;padding:.75rem 1rem;border-radius:var(--radius);display:flex;flex-direction:column;gap:.3rem;">
                        <span style="font-weight:600;font-size:.85rem;color:var(--color-text);display:flex;align-items:center;gap:.4rem;">
                            <i data-lucide="layout" style="width:14px;height:14px;color:var(--color-primary)"></i> Plan académique complet
                        </span>
                        <span style="font-size:.74rem;color:var(--color-text-muted);line-height:1.35;">Parties, chapitres, sections et sous-sections normalisées.</span>
                    </button>
                </div>
            </div>

            {{-- Composer moderne --}}
            <form class="chat-composer" action="{{ route('chat.send') }}" method="POST" id="chat-form">
                @csrf
                <div class="composer-box" style="box-shadow:var(--shadow-1);">
                    <textarea name="message" id="chat-input" rows="1" required maxlength="12000"
                              placeholder="Posez une question ou demandez une action sur votre rapport…"
                              aria-label="Votre message"></textarea>
                    <div class="composer-tools">
                        <button type="button" class="composer-tool-btn" title="Outils et actions rapides"
                                onclick="document.getElementById('tools-hint').style.display = document.getElementById('tools-hint').style.display === 'none' ? 'flex' : 'none'">
                            <i data-lucide="wrench"></i>
                        </button>
                    </div>
                    <button type="submit" class="composer-send" id="chat-send" aria-label="Envoyer" title="Envoyer le message">
                        <i data-lucide="arrow-up"></i>
                    </button>
                </div>

                <div class="composer-hint">
                    <span class="chat-cost-preview">
                        <i data-lucide="coins"></i>
                        <span class="cost-label">Coût estimé :</span>
                        <strong>{{ $estimatedCredits ?? 0 }} crédit(s)</strong>
                        <span class="cost-precision">(ajusté après usage)</span>
                        <span class="cost-precision-court">(estimation)</span>
                    </span>
                    <span class="hint-keys">
                        <span class="kbd">Ctrl</span><span class="kbd">⏎</span> pour envoyer
                    </span>
                </div>

                <div class="composer-actions" id="tools-hint">
                    <button type="button" class="chat-action-btn" data-prompt="Analyse la structure de ma pièce jointe et vérifie sa conformité.">
                        <i data-lucide="search-check"></i> Analyser
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Reconstruis mon document avec la structure détectée.">
                        <i data-lucide="file-cog"></i> Reconstruction
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Cherche des sources récentes sur le sujet de mon rapport.">
                        <i data-lucide="globe"></i> Recherche web
                    </button>
                    <button type="button" class="chat-action-btn" data-prompt="Génère une illustration académique pour mon rapport.">
                        <i data-lucide="image"></i> Image
                    </button>
                    @if ($claudeEligible ?? false)
                        <button type="button" class="chat-action-btn" data-prompt="Génère un fichier Excel récapitulatif de mon rapport.">
                            <i data-lucide="table"></i> Fichier Excel (Claude)
                        </button>
                    @endif
                </div>

                <p class="chat-disclaimer">
                    Une nouvelle conversation sera créée. Facturation au coût réel (ajustement automatique). En cas d'échec, vos crédits sont remboursés.
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

        // Actions rapides et chips → remplir le champ
        document.querySelectorAll('.chat-action-btn, .suggest-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                if (ta) { 
                    ta.value = btn.dataset.prompt; 
                    ta.dispatchEvent(new Event('input')); 
                    ta.focus(); 
                }
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
    </script>
@endsection
