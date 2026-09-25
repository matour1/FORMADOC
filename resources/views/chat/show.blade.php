@extends('layouts.app')

@section('title', 'Conversation — Assistant IA')

@php
    // Le bloc qui calculait les modèles du plan a été retiré avec le sélecteur.
    //
    // Le modèle RÉELLEMENT utilisé est déjà affiché dans l'en-tête de page
    // (`$chatSession->model_used`), et c'est lui qui fait foi. Afficher en plus le
    // modèle « préféré » du plan aurait donné deux noms différents à l'écran, dont
    // un qui ne reflète pas ce qui s'est passé — et sur un écran étroit, la note se
    // retrouvait tronquée au milieu du nom.
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

        {{-- Sidebar conversations : COLONNE sur grand ecran, TIROIR sur mobile.
             Voir le CSS `@media (max-width: 900px)` — mesure avant correction :
             la liste occupait 180 px en permanence et ne laissait que 102 px a la
             conversation, soit 14 % de l'ecran. ChatGPT et Claude masquent cette
             liste derriere un bouton ; on applique le meme modele. --}}
        <aside class="chat-sidebar" id="chatSidebar" aria-label="Conversations">
            <div class="chat-sidebar-head">
                <span class="chat-sidebar-title">Conversations</span>
                <div style="display:flex;gap:.25rem;align-items:center">
                    <a href="{{ route('chat.index') }}" class="icon-btn" title="Nouvelle conversation" aria-label="Nouvelle conversation" style="width:30px;height:30px">
                        <i data-lucide="square-pen" style="width:15px;height:15px"></i>
                    </a>
                    {{-- Fermeture du tiroir : visible seulement quand il est ouvert,
                         donc sur mobile. Sur grand ecran il n'y a rien a fermer. --}}
                    <button type="button" class="icon-btn chat-drawer-close" id="chatDrawerClose"
                            aria-label="Fermer la liste des conversations" style="width:30px;height:30px">
                        <i data-lucide="x" style="width:15px;height:15px"></i>
                    </button>
                </div>
            </div>

            <div class="chat-search-bar" style="margin-bottom:.5rem">
                <i data-lucide="search" style="width:13px;height:13px;color:var(--color-text-muted)"></i>
                <input type="text" id="chatSidebarSearch" placeholder="Rechercher…" style="border:none;background:transparent;font:inherit;color:var(--color-text);outline:none;flex:1">
            </div>

            @foreach ($sessions as $session)
                <div class="chat-item {{ $session->id === $chatSession->id ? 'active' : '' }}"
                     {{-- `role="button" tabindex="0"` etait deja present, mais le
                          gestionnaire ne repondait qu'a `Enter` : la barre d'espace
                          etait ignoree, alors que c'est la touche qu'on emploie
                          naturellement sur un bouton. Les deux sont desormais
                          traitees, et `preventDefault` evite que l'espace fasse
                          defiler la page. --}}
                     role="button" tabindex="0"
                     aria-current="{{ $session->id === $chatSession->id ? 'true' : 'false' }}"
                     onclick="window.location='{{ route('chat.show', $session) }}'"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('chat.show', $session) }}'}">
                    <span class="chat-item-icon" aria-hidden="true"><i data-lucide="message-circle"></i></span>
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

            {{-- Meme correction que dans la liste des conversations : aucune duree de
                 retention n'est appliquee, donc aucune ne doit etre annoncee. --}}
            <div class="chat-sidebar-footer mono">
                Suppression à la demande · Quota IA : {{ $quotaStatus['ai']['used'] }}/{{ $quotaStatus['ai']['unlimited'] ? '∞' : $quotaStatus['ai']['quota'] }}
            </div>
        </aside>

        {{-- Zone principale --}}
        <div class="chat-main">

            {{-- En-tête --}}
            <div class="chat-main-header">
                <div class="chat-context">
                    {{-- Ouverture du tiroir des conversations : le seul acces a la
                         liste sur mobile. Sans lui, changer de conversation
                         demanderait de passer par « Mes conversations », soit un
                         aller-retour par une page intermediaire. --}}
                    <button type="button" class="chat-drawer-toggle" id="chatDrawerToggle"
                            aria-label="Ouvrir la liste des conversations"
                            aria-controls="chatSidebar" aria-expanded="false">
                        <i data-lucide="panel-left" style="width:16px;height:16px"></i>
                    </button>
                    <span class="chat-context-dot" aria-hidden="true"></span>
                    <strong>Assistant FORMADOC</strong>
                    <span class="chat-context-info">— outils actionnables : analyse, reconstruction, recherche web, images</span>
                </div>
                <div class="chat-header-actions">
                    {{-- Le mode d'exécution est AUTOMATIQUE (config chat.mode = agent) :
                         les outils sont systématiquement proposés et l'IA les exécute
                         elle-même. Aucun sélecteur n'est exposé à l'utilisateur. --}}

                    {{--
                        Le SELECTEUR DE MODELE a ete retire, et c'est une correction, pas
                        une simplification.

                        Il affichait « Chat / Analyse / Mise en forme / Image » et laissait
                        croire qu'on choisissait le modele. Or aucun champ n'etait envoye au
                        serveur : ChatController::send() ne lit que `message` et
                        `attachments`. Le choix etait purement local — le modele reellement
                        utilise est celui que ModelRouter choisit selon la tache.

                        Une interface qui propose un choix sans effet est pire qu'une absence
                        d'interface : l'utilisateur croit avoir agi. On affiche donc le
                        routage REEL, en lecture.
                    --}}
                    <span class="chat-model-note" title="Le modele est choisi automatiquement selon la tache et votre plan. Le modele utilise figure en haut de la conversation.">
                        <i data-lucide="cpu" style="width:13px;height:13px"></i>
                        Routage automatique
                    </span>
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
                            {{--
                                Rendu markdown, PAS de texte brut.

                                La version precedente utilisait nl2br(e(...)) — donc le
                                markdown s'affichait tel quel : « ### Resume de l'analyse »
                                et « **Titres detectes** » apparaissaient litteralement dans
                                les reponses. Verifie sur un message reel en base.

                                La directive @markdown rend un HTML SÛR : le contenu est
                                echappe AVANT toute transformation (voir
                                ChatMarkdownRenderer), parce qu'il vient d'un modele qui a
                                lu un document non fiable — donc potentiellement hostile.
                            --}}
                            @markdown($message->content)
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
                                {{-- Q-OUTILS : quels outils ont RÉELLEMENT tourné pour ce
                                     message, d'après `metadata.tools_used`.

                                     C'est la réponse honnête à « montrer les étapes ».
                                     L'envoi d'un message est une requête synchrone : le
                                     navigateur ne reçoit RIEN tant que le serveur n'a pas
                                     fini, donc il ne peut pas afficher « l'analyse
                                     commence » — il ne le sait pas. Après coup, en
                                     revanche, les outils exécutés sont connus avec
                                     certitude. On affiche donc le vrai travail effectué,
                                     au lieu d'une progression inventée.

                                     Seuls les outils RÉUSSIS sont listés (voir
                                     ChatController) : annoncer une action qui a échoué
                                     serait pire que de ne rien dire. --}}
                                @if (! empty($message->metadata['tools_used']))
                                    <div class="msg-tools" style="margin-top:.55rem;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center">
                                        <span class="msg-tools-label">
                                            <i data-lucide="wrench" style="width:12px;height:12px;display:inline-block;vertical-align:-2px"></i>
                                            {{ count($message->metadata['tools_used']) > 1 ? 'Outils utilisés' : 'Outil utilisé' }}
                                        </span>
                                        @foreach ($message->metadata['tools_used'] as $outil)
                                            {{-- `badge-info` est OBLIGATOIRE : dans le
                                                 design system, `.badge` seul ne porte ni
                                                 fond ni couleur — ce sont les variantes
                                                 qui les definissent. Sans variante, la
                                                 pastille s'affichait en texte nu (defaut
                                                 vu a la capture, invisible aux tests, qui
                                                 verifient le contenu et non le style). --}}
                                            <span class="badge badge-info" title="{{ $outil }}">
                                                {{ config('chat.tool_labels.'.$outil, $outil) }}
                                            </span>
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
                        <p style="font-size:.78rem;margin-top:.3rem">Exemples : « analyse la structure de ma pièce jointe », « reconstruis mon document », « cherche des sources sur le changement climatique au Cameroun »</p>
                    </div>
                @endforelse

                {{-- Chips de suggestions (quand la conversation est vide) --}}
                @if ($messages->isEmpty())
                    <div class="suggest-chips" id="chatSuggest">
                        <button type="button" class="suggest-chip" data-prompt="Analyse la structure de ma pièce jointe et dis-moi ce que tu y détectes.">Analyser un rapport</button>
                        <button type="button" class="suggest-chip" data-prompt="Reconstruis mon document avec la structure détectée.">Reconstruire un document</button>
                        <button type="button" class="suggest-chip" data-prompt="Cherche des sources récentes sur le changement climatique au Cameroun.">Recherche web</button>
                        <button type="button" class="suggest-chip" data-prompt="Génère une image pour illustrer mon rapport.">Générer une image</button>
                    </div>
                @endif

                {{-- Indicateur d'activite (style Claude).
                     Le detail de ce qu'il annonce, et surtout ce qu'il N'annonce PAS,
                     est explique dans les commentaires du script plus bas : l'envoi
                     est synchrone, donc aucun avancement reel n'est observable par
                     la page. --}}
                <div class="thinking-indicator" id="typingIndicator" style="display:none"
                     role="status" aria-live="polite" aria-atomic="true">
                    <span class="thinking-avatar" aria-hidden="true"><i data-lucide="sparkles" style="width:14px;height:14px"></i></span>
                    <span class="thinking-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span class="thinking-label" id="thinkingLabel">L'assistant traite votre demande…</span>
                    <span class="thinking-elapsed mono" id="thinkingElapsed" role="timer" aria-live="off">0 s</span>
                </div>
                {{-- Avertissement d'attente longue : apparaît seulement si le delai
                     depasse ce qui est habituel, pour ne pas inquieter sans raison. --}}
                <div class="thinking-notice" id="thinkingNotice" style="display:none" role="status">
                    <i data-lucide="info" aria-hidden="true"></i>
                    <span>
                        Le traitement continue. Les actions sur un document (analyse,
                        conversion, mise en forme) prennent souvent une a deux minutes.
                        Ne fermez pas cette page.
                    </span>
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
                              placeholder="Votre message…"
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
                        {{-- Le libelle est dans son propre span, et non en texte nu.
                             Un enfant flex anonyme se comprime jusqu'a devenir plus
                             etroit que son mot le plus long, et le navigateur coupe
                             alors EN PLEIN MOT : « Cout estime » s'affichait
                             « C o u t  e s t i m e » a 390 px. Un element a part
                             entiere se replie au contraire d'un bloc. --}}
                        <span class="cost-label">Coût estimé :</span>
                        <strong>{{ $chatCostEstimate ?? 0 }} crédit(s)</strong>
                        {{-- Deux formes, une seule affichee selon la largeur. Mesure a
                             390 px : la forme longue fait passer l'indice sur deux
                             lignes et le mot « Cout » s'y coupait.

                             On ne supprime PAS la precision pour autant. Elle porte une
                             information materielle : le montant affiche est une
                             ESTIMATION qui sera corrigee apres usage. La retirer ferait
                             passer le montant pour un prix definitif — exactement l'ecart
                             qui produit une reclamation. On garde donc l'idee, sous une
                             forme qui tient sur une ligne. --}}
                        <span class="cost-precision">(modèles du plan, ajusté après usage)</span>
                        <span class="cost-precision-court">(estimation)</span>
                    </span>
                    <span class="hint-keys">
                        <span class="kbd">Ctrl</span><span class="kbd">⏎</span> pour envoyer
                    </span>
                </div>

                {{-- Actions rapides (outils) --}}
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
                    Le coût en crédits est estimé avant l'envoi et ajusté après usage. En cas d'échec, vos crédits sont remboursés.
                    @if ($claudeEligible ?? false)
                        Skills documentaires Claude activés (expérimental) : inclus Standard+, sinon pay-per-use en crédits (×1,5).
                    @endif
                </p>
            </form>
        </div>
    </div>

    {{-- Overlay du tiroir des conversations (mobile uniquement).
         Il assombrit la conversation et, surtout, la fermeture au toucher : sans
         lui, il faudrait viser a nouveau le bouton d'ouverture pour refermer, un
         geste peu decouvrable sur un ecran tactile. --}}
    <div class="chat-sidebar-overlay" id="chatSidebarOverlay" aria-hidden="true"></div>

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
        // --- Etat de chargement pendant l'envoi -------------------------------
        //
        // CE QUI EST POSSIBLE, ET CE QUI NE L'EST PAS.
        //
        // Le formulaire est envoye en POST classique : le navigateur quitte la page
        // et n'en reçoit la reponse qu'a la fin du traitement. La page ne peut donc
        // connaitre NI l'avancement, NI l'outil en cours d'execution — ces
        // informations n'existent nulle part cote client. Afficher une barre de
        // progression, un pourcentage ou « Analyse du document… puis Conversion… »
        // serait INVENTE, et donnerait une information fausse a l'utilisateur.
        //
        // Ce qui est reellement observable, en revanche :
        //   - que le traitement est en cours (l'utilisateur vient de soumettre) ;
        //   - depuis COMBIEN DE TEMPS (mesure reelle, celle du navigateur) ;
        //   - que le delai depasse l'habitude (seuil, annonce comme un seuil).
        //
        // C'est le meme choix que l'ecran de traitement des documents, qui affiche
        // un temps ecoule reel et une barre indeterminee plutot qu'un pourcentage
        // fabrique — il n'existe aucune colonne de jalons pour le mesurer.
        const chatForm = document.getElementById('chat-form');
        const typingIndicator = document.getElementById('typingIndicator');
        // `chatSend` est declare ici : il est utilise dans le gestionnaire de
        // soumission ET dans le nettoyage `pageshow` ci-dessous. Sans cette
        // declaration, la page levait « chatSend is not defined » a l'envoi, ce
        // qui empechait l'indicateur de s'afficher et desactivait silencieusement
        // le bouton — le formulaire restait utilisable, mais l'interface mentait
        // sur l'etat de l'application.
        const chatSend = document.getElementById('chat-send');

        // Une soumission peut etre annulee par la validation HTML5 du navigateur
        // (champ vide, fichier trop lourd) : `submit` n'est alors PAS declenche, ce
        // qui est le comportement voulu — on ne montre pas « traitement en cours »
        // pour un formulaire refuse.
        let timerElapsed = null;
        let timerNotice = null;

        const demarrerIndicateur = () => {
            if (!typingIndicator) return;

            const debut = Date.now();
            const label = document.getElementById('thinkingLabel');
            const elapsed = document.getElementById('thinkingElapsed');
            const notice = document.getElementById('thinkingNotice');

            typingIndicator.style.display = 'flex';

            // Le temps ecoule est annonce toutes les secondes. `aria-live` reste a
            // `off` sur ce compteur : une annonce vocale a chaque seconde rendrait
            // l'interface inutilisable avec un lecteur d'ecran. C'est l'indicateur
            // lui-meme (`role="status"`, `aria-live="polite"`) qui porte le message.
            timerElapsed = window.setInterval(() => {
                const s = Math.floor((Date.now() - debut) / 1000);
                if (elapsed) elapsed.textContent = s + ' s';

                // Au-dela de 20 s, on nomme ce que l'assistant est probablement en
                // train de faire SANS l'affirmer : la mention precise que le
                // traitement continue et qu'il ne faut pas fermer la page. Le seuil
                // est volontairement au-dessus du temps de reponse habituel, pour
                // que l'avertissement garde sa valeur de signal.
                if (s >= 20 && label) {
                    label.textContent = 'Traitement en cours…';
                }
                if (s >= 45 && notice) {
                    notice.style.display = 'flex';
                }
            }, 1000);
        };

        if (chatForm) {
            chatForm.addEventListener('submit', () => {
                const input = document.getElementById('chat-input');
                if (!input || !input.value.trim()) return; // Laisse le required gerer

                demarrerIndicateur();

                // IMPORTANT : `readOnly` et non `disabled`. Un champ `disabled`
                // n'est PAS transmis avec le formulaire, et Laravel repondrait
                // « The message field is required ». Ce piege a deja ete rencontre
                // sur ce formulaire, le commentaire est conserve pour l'eviter.
                if (chatSend) { chatSend.disabled = true; chatSend.setAttribute('aria-busy', 'true'); }
                if (input) input.readOnly = true;
            });
        }

        // Si l'utilisateur revient sur la page (bouton « precedent » du navigateur),
        // la restauration depuis le cache d'arriere-plan remet l'interface dans
        // l'etat ou elle a ete quittee : indicateur affiche, champ desactive. Sans
        // ce nettoyage, l'utilisateur croirait le traitement toujours en cours
        // alors qu'il est termine — et ne pourrait plus rien ecrire.
        window.addEventListener('pageshow', (e) => {
            if (!e.persisted && !document.getElementById('thinkingNotice')) return;

            window.clearInterval(timerElapsed);
            window.clearInterval(timerNotice);
            if (typingIndicator) typingIndicator.style.display = 'none';
            const notice = document.getElementById('thinkingNotice');
            if (notice) notice.style.display = 'none';
            if (chatSend) { chatSend.disabled = false; chatSend.removeAttribute('aria-busy'); }
            const input = document.getElementById('chat-input');
            if (input) input.readOnly = false;
        });

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

        // Le sélecteur de modèle a été retiré : il laissait croire qu'on choisissait
        // le modèle, alors qu'aucun champ n'était envoyé au serveur. Le routage est
        // fait par ModelRouter selon la tâche et le plan.

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

        // --- Tiroir des conversations (mobile) -------------------------------
        //
        // SUR MOBILE, la liste des conversations sort du flux et devient un
        // tiroir. Mesure avant correction : elle occupait 180 px en permanence et
        // ne laissait que 102 px a la conversation, soit 14 % de l'ecran — la
        // liste prenait presque deux fois plus de place que le contenu.
        //
        // ChatGPT et Claude sur mobile procedent ainsi, et c'est le bon modele :
        // la conversation occupe tout l'espace, la liste s'ouvre a la demande.
        //
        // L'ouverture/fermeture suit le pattern deja utilise par la navigation
        // principale (`.sidebar` + `.sidebar-overlay`) : coherence avec le reste
        // du produit, et un comportement clavier deja connu des utilisateurs.
        const chatSidebar = document.getElementById('chatSidebar');
        const chatOverlay = document.getElementById('chatSidebarOverlay');
        const drawerToggle = document.getElementById('chatDrawerToggle');
        const drawerClose = document.getElementById('chatDrawerClose');

        const fermerTiroir = () => {
            chatSidebar?.classList.remove('open');
            chatOverlay?.classList.remove('open');
            drawerToggle?.setAttribute('aria-expanded', 'false');
            // Le focus revient au bouton qui a ouvert le tiroir : sans cela, il
            // reste sur un element devenu invisible, et la navigation au clavier
            // repart du debut de la page.
            drawerToggle?.focus();
        };

        const ouvrirTiroir = () => {
            chatSidebar?.classList.add('open');
            chatOverlay?.classList.add('open');
            drawerToggle?.setAttribute('aria-expanded', 'true');
            // Le focus entre dans le tiroir : la liste est utilisable au clavier
            // des son ouverture, sans avoir a la parcourir depuis le debut.
            chatSidebar?.querySelector('.chat-item')?.focus();
        };

        drawerToggle?.addEventListener('click', () => {
            if (chatSidebar?.classList.contains('open')) fermerTiroir();
            else ouvrirTiroir();
        });

        drawerClose?.addEventListener('click', fermerTiroir);
        chatOverlay?.addEventListener('click', fermerTiroir);

        // Echap ferme le tiroir : c'est le geste attendu d'un panneau superpose,
        // et le meme que pour la navigation principale.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && chatSidebar?.classList.contains('open')) fermerTiroir();
        });

        // Choisir une conversation ferme le tiroir. Le `onclick` de chaque item
        // navigue deja ; on ferme en plus pour que le retour en arriere (bouton
        // « precedent ») ne rende pas un tiroir ouvert par-dessus la conversation.
        chatSidebar?.querySelectorAll('.chat-item').forEach((item) => {
            item.addEventListener('click', () => {
                chatSidebar.classList.remove('open');
                chatOverlay?.classList.remove('open');
            });
        });

        // Si la fenetre est elargie au-dela du seuil mobile alors que le tiroir
        // est ouvert, on le remet en etat : sinon, le retour a une largeur mobile
        // retrouverait un tiroir ouvert et un overlay qui bloque la conversation.
        window.addEventListener('resize', () => {
            if (window.innerWidth > 900 && chatSidebar?.classList.contains('open')) {
                chatSidebar.classList.remove('open');
                chatOverlay?.classList.remove('open');
                drawerToggle?.setAttribute('aria-expanded', 'false');
            }
        });

        // Le defilement vers le bas etait fait ici ET dans le bloc de scripts
        // pousse en fin de fichier : deux gestionnaires pour un meme effet. On
        // garde le second, qui attend DOMContentLoaded — le script inline, lui,
        // s'executait avant que les icones et la hauteur finale soient posees.
        //
        // ATTENTION : ne jamais ecrire une directive Blade entre accents graves
        // dans un commentaire. Blade compile les directives MEME a l'interieur
        // d'une balise <script> : la mention litterale de la directive de
        // defilement a fait planter la page entiere (« Undefined property:
        // Illuminate\View\Factory::$startPush »). Pour eviter le probleme, on
        // ecrit le nom sans son arobase.
    </script>
@endsection


@push('scripts')
    <script>
        // Defilement en bas de la conversation au chargement.
        //
        // Dans `DOMContentLoaded` et non en script inline : avant cet evenement,
        // les polices ne sont pas appliquees et donc `scrollHeight` n'est pas
        // encore la hauteur definitive — le defilement s'arreterait trop haut.
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('chat-messages');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    </script>
@endpush
