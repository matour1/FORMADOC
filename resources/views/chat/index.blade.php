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

        {{--
            Le BANDEAU DE CONFIRMATION DE COUT a ete retire.

            Il s'affichait sur `session('pending_cost')`, un flash que PLUS AUCUN
            code ne pose : l'etape de confirmation a ete supprimee du controleur.
            Le bandeau etait donc du code mort — mais aussi un mensonge en
            puissance : s'il s'affichait un jour, il proposerait « Confirmer et
            envoyer » pour un mecanisme inexistant, en renvoyant `confirm_cost`,
            champ que `send()` n'a jamais lu.

            Le cout reste annonce AVANT envoi, dans le composer (estimation du
            plan), puis ajuste apres usage.
        --}}

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
                <div class="chat-empty" id="chatEmptyState">
                    Aucune conversation pour le moment.
                </div>
            @endforelse
            </div>
            <div class="chat-empty compact" id="chatSearchEmpty" style="display:none">
                Aucune conversation ne correspond.
            </div>

            {{--
                L'ancien libelle annoncait « Historique conserve 30 jours ».
                C'ETAIT FAUX, et verifiable : `files:purge-temp` ne supprime que
                les fichiers temporaires et les pieces jointes de sessions DEJA
                supprimees. Aucune tache ne purge les conversations par anciennete,
                et aucune duree de retention n'est configuree.

                Une duree de conservation annoncee et non appliquee est un
                engagement de confidentialite non tenu : l'utilisateur peut croire
                ses echanges effaces alors qu'ils restent en base indefiniment.
                On annonce donc ce qui est vrai — la suppression est manuelle —
                plutot qu'un delai qui n'existe pas.
            --}}
            <div class="chat-sidebar-footer mono">
                Vous supprimez vos conversations quand vous le souhaitez · <span id="chatQuotaFooter"></span>
            </div>
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
                    {{--
                        Le SELECTEUR DE MODELE a ete retire : il laissait croire qu'on
                        choisissait le modele, alors qu'aucun champ n'etait envoye au
                        serveur (ChatController::send() ne lit que `message` et
                        `attachments`). Une interface qui propose un choix sans effet est
                        pire qu'une absence d'interface : l'utilisateur croit avoir agi.

                        On affiche donc le routage REEL, en lecture.
                    --}}
                    <span class="chat-model-note" title="Le modele est choisi automatiquement selon la tache et votre plan.">
                        <i data-lucide="cpu" style="width:13px;height:13px"></i>
                        Routage automatique
                    </span>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages" style="align-content:center">
                <div class="empty-state" style="text-align:center;padding:2rem 1rem;color:var(--color-text-muted)">
                    <i data-lucide="sparkles" style="width:44px;height:44px;margin:0 auto .8rem;display:block;opacity:.6"></i>
                    <p style="font-size:1.05rem;font-weight:600;color:var(--color-text)">Comment puis-je vous aider ?</p>
                    <p style="font-size:.82rem;margin-top:.3rem">L'assistant peut analyser, mettre en forme et agir sur vos documents.</p>
                </div>

                <div class="suggest-chips" id="chatSuggest">
                    <button type="button" class="suggest-chip" data-prompt="Analyse la structure de ma pièce jointe et dis-moi ce que tu y détectes.">Analyser un rapport</button>
                    <button type="button" class="suggest-chip" data-prompt="Explique-moi comment structurer un rapport de stage de 30 pages.">Structurer un rapport</button>
                    <button type="button" class="suggest-chip" data-prompt="Cherche des sources récentes sur le changement climatique au Cameroun.">Recherche web</button>
                    <button type="button" class="suggest-chip" data-prompt="Génère une image pour illustrer mon rapport.">Générer une image</button>
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
                    <button type="button" class="chat-action-btn" data-prompt="Analyse la structure de ma pièce jointe.">
                        <i data-lucide="search-check"></i> Analyser
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

        // Le selecteur de modele a ete retire : il laissait croire qu'on choisissait
        // le modele, alors qu'aucun champ n'etait envoye au serveur. Le routage est
        // fait par ModelRouter selon la tache et le plan.
    </script>
@endsection

