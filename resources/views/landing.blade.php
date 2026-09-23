<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FORMADOC met en forme automatiquement vos rapports, mémoires et CV — DQP, BTS, licence, master. 5 documents gratuits par mois, assistance IA optionnelle à coût affiché.">
    <meta name="theme-color" content="#0f172a">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%230f172a'/><text x='50' y='68' font-size='52' text-anchor='middle' fill='white' font-family='Georgia'>FD</text></svg>">
    <title>FORMADOC — Mise en forme automatique de rapports, mémoires et CV</title>

    {{-- Polices : Newsreader (titres), Plus Jakarta Sans (corps), IBM Plex Mono (données) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- Design system + Vite --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body class="landing-page">
    <a class="skip-link" href="#main">Aller au contenu principal</a>

    <header class="landing-nav">
        <div class="wrap">
            <a class="brand" href="#main"><span class="mark">FD</span> FORMADOC</a>
            <nav class="nav-links" aria-label="Navigation principale">
                <a href="#fonctionnalites">Fonctionnalités</a>
                <a href="#parcours">Comment ça marche</a>
                <a href="#tarifs">Tarifs</a>
                <a href="#faq">FAQ</a>
            </nav>
            <div class="nav-actions">
                <button class="theme-toggle" type="button" id="themeToggle" aria-label="Basculer le thème clair/sombre" aria-pressed="false">
                    <i data-lucide="moon" style="width:17px;height:17px"></i>
                </button>
                @auth
                    <a class="btn btn-ghost" href="{{ route('account.index') }}">Mon compte</a>
                    <a class="btn btn-primary" href="{{ route('documents.create') }}">Analyser un rapport</a>
                @else
                    <a class="btn btn-ghost" href="{{ route('login') }}">Connexion</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">Essayer gratuitement</a>
                @endauth
                <button class="navbar-menu-toggle" type="button" aria-label="Ouvrir le menu" aria-expanded="false" id="landing-menu-toggle">
                    <i data-lucide="menu" style="width:22px;height:22px"></i>
                </button>
            </div>
        </div>
        {{-- Menu mobile --}}
        <div class="landing-mobile-menu" id="landing-mobile-menu" aria-hidden="true">
            <nav aria-label="Navigation mobile">
                <a href="#fonctionnalites">Fonctionnalités</a>
                <a href="#parcours">Comment ça marche</a>
                <a href="#tarifs">Tarifs</a>
                <a href="#faq">FAQ</a>
            </nav>
            <div class="landing-mobile-actions">
                @auth
                    <a class="btn btn-ghost btn-block" href="{{ route('account.index') }}">Mon compte</a>
                    <a class="btn btn-primary btn-block" href="{{ route('documents.create') }}">Analyser un rapport</a>
                @else
                    <a class="btn btn-ghost btn-block" href="{{ route('login') }}">Connexion</a>
                    <a class="btn btn-primary btn-block" href="{{ route('register') }}">Essayer gratuitement</a>
                @endauth
            </div>
        </div>
    </header>

    <main id="main">

        <!-- HERO -->
        <section class="hero">
            <div class="wrap">
                <div>
                    <span class="eyebrow">FORMADOC SaaS</span>
                    <h1>Vos documents, mis en forme au cordeau.</h1>
                    <p>Rapports, mémoires, CV et documents professionnels transformés automatiquement en fichiers propres et cohérents — avec ou sans assistance IA.</p>
                    <div class="hero-actions">
                        <a class="btn btn-primary btn-lg" href="{{ auth()->check() ? route('documents.create') : route('register') }}">Commencer gratuitement</a>
                        <a class="btn btn-secondary btn-lg" href="#tarifs">Voir les tarifs</a>
                    </div>
                    <a class="hero-example-link" href="{{ route('sample-document.download') }}">
                        <i data-lucide="file-down" style="width:15px;height:15px"></i>
                        Voir un exemple de résultat
                    </a>
                    <div class="hero-proof">
                        <span class="proof-stamp">DOCX · DOC · TXT</span>
                        <span class="badge badge-success">5 documents gratuits/mois</span>
                        <span class="badge badge-info">IA optionnelle</span>
                    </div>
                </div>

                <div class="document-stage" aria-label="Aperçu visuel de transformation documentaire">
                    <div class="doc-sheet">
                        <div class="doc-line title"></div>
                        <div class="doc-line w55"></div>
                        <div class="doc-line w90"></div>
                        <div class="doc-line w75"></div>
                        <div class="doc-line w90"></div>
                        <div class="doc-table"><span></span><span></span><span></span><span></span><span></span><span></span></div>
                        <div class="doc-line w75"></div>
                        <div class="doc-line w90"></div>
                    </div>
                    <div class="flow-card">
                        <div style="font-weight:700;font-size:.86rem;">Analyse en cours</div>
                        <div class="mono" style="font-size:.72rem;color:var(--color-text-muted);">Structure · styles · figures</div>
                        <div class="progress"><div class="progress-bar"></div></div>
                    </div>
                    <div class="doc-sheet after">
                        <div class="doc-line title"></div>
                        <div class="doc-line accent"></div>
                        <div class="doc-line w90"></div>
                        <div class="doc-line w90"></div>
                        <div class="doc-line w75"></div>
                        <div class="doc-table"><span></span><span></span><span></span><span></span><span></span><span></span></div>
                        <div class="doc-line w90"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TRUST STRIP -->
        <div class="trust-strip">
            <div class="wrap">
                <span>MODE DÉTERMINISTE TOUJOURS DISPONIBLE</span>
                <span>·</span>
                <span>PAIEMENT KPAY · ORANGE MONEY · MTN MOMO</span>
                <span>·</span>
                <span>COÛT AFFICHÉ AVANT TOUTE ACTION IA</span>
            </div>
        </div>

        <!-- FEATURES -->
        <section id="fonctionnalites">
            <div class="wrap">
                <div class="section-head">
                    <span class="eyebrow">Fonctionnalités</span>
                    <h2 class="section-title">Tout ce qu'il faut pour un document impeccable</h2>
                    <p class="section-sub">Un moteur déterministe fait le travail de base ; l'IA n'intervient que si vous le décidez, et son coût est toujours visible avant exécution.</p>
                </div>
                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="align-left" style="width:19px;height:19px"></i></div>
                        <h3>Structure détectée</h3>
                        <p>Titres, listes, tableaux, images, légendes et notes de bas de page sont reconnus automatiquement.</p>
                    </div>
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="pen-tool" style="width:19px;height:19px"></i></div>
                        <h3>Gabarits maîtrisés</h3>
                        <p>Modèles académiques, professionnels ou personnalisés — appliqués sans jamais perdre le contenu.</p>
                    </div>
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="list-ordered" style="width:19px;height:19px"></i></div>
                        <h3>Numérotation et sommaire</h3>
                        <p>Figures, tableaux et annexes renumérotés par ordre d'apparition, avec sommaire et listes générés automatiquement.</p>
                    </div>
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="message-square" style="width:19px;height:19px"></i></div>
                        <h3>Assistant IA intégré</h3>
                        <p>Le modèle le plus adapté est choisi automatiquement selon la tâche — analyse, correction ou mise en forme.</p>
                    </div>
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="coins" style="width:19px;height:19px"></i></div>
                        <h3>Crédits transparents</h3>
                        <p>Chaque action IA affiche son coût estimé avant exécution ; repli gratuit sans IA à tout moment.</p>
                    </div>
                    <div class="feature-item">
                        <div class="icon-wrap"><i data-lucide="eye" style="width:19px;height:19px"></i></div>
                        <h3>Aperçu fidèle</h3>
                        <p>Contrôlez le rendu PDF avant de télécharger le document final au format DOCX.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- COMMENT ÇA MARCHE -->
        <section id="parcours" style="background:var(--color-surface-2);">
            <div class="wrap">
                <div class="section-head">
                    <span class="eyebrow">Parcours</span>
                    <h2 class="section-title">De votre brouillon au document final, en quatre étapes</h2>
                </div>
                <div class="steps">
                    <div class="step-item">
                        <div class="step-num">1</div>
                        <h3>Téléversez</h3>
                        <p>Un fichier DOCX, DOC ou TXT, jusqu'à 50 Mo.</p>
                    </div>
                    <div class="step-item">
                        <div class="step-num">2</div>
                        <h3>Choisissez un gabarit</h3>
                        <p>Un modèle public, ou le vôtre.</p>
                    </div>
                    <div class="step-item">
                        <div class="step-num">3</div>
                        <h3>IA facultative</h3>
                        <p>Coût affiché avant validation, repli gratuit possible.</p>
                    </div>
                    <div class="step-item">
                        <div class="step-num">4</div>
                        <h3>Téléchargez</h3>
                        <p>Aperçu PDF, puis export DOCX final.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- TARIFS -->
        <section id="tarifs">
            <div class="wrap">
                <div class="section-head">
                    <span class="eyebrow">Tarifs</span>
                    <h2 class="section-title">Un plan pour chaque usage</h2>
                    <p class="section-sub">1 crédit = 1 FCFA. Achetez des crédits ponctuellement ou choisissez un abonnement mensuel, résiliable à tout moment.</p>
                </div>
                <div class="plan-grid">
                    {{-- Plan Gratuit (par défaut, non présent en base) --}}
                    <div class="plan-card">
                        <div class="plan-name">Gratuit</div>
                        <div class="price mono">0 <span>FCFA/mois</span></div>
                        <div class="plan-desc">Pour commencer gratuitement, sans carte bancaire.</div>
                        <ul>
                            <li>5 documents / mois</li>
                            <li>Mode déterministe uniquement</li>
                            <li>Gabarits de mise en forme publics</li>
                            <li>Numérotation et sommaire inclus</li>
                            <li>Chat IA au coût réel (crédits)</li>
                        </ul>
                        <a class="btn btn-secondary" href="{{ route('register') }}">Commencer</a>
                    </div>

                    @foreach ($plans as $plan)
                        <div class="plan-card {{ $plan->slug === 'premium' ? 'popular' : '' }}">
                            @if ($plan->slug === 'premium')
                                <span class="plan-tag">Populaire</span>
                            @endif
                            <div class="plan-name">{{ $plan->name }}</div>
                            <div class="price mono">
                                @if ($plan->price_fcfa > 0)
                                    {{ number_format($plan->price_fcfa, 0, ',', ' ') }} <span>FCFA/mois</span>
                                @else
                                    Sur devis
                                @endif
                            </div>
                            <div class="plan-desc">{{ $plan->description }}</div>
                            <ul>
                                @foreach (array_slice($plan->features ?? [], 0, 4) as $feature)
                                    <li>{{ $feature }}</li>
                                @endforeach
                            </ul>
                            <a class="btn {{ $plan->slug === 'premium' ? 'btn-primary' : 'btn-secondary' }}"
                               href="{{ $plan->slug === 'enterprise' ? '#contact' : route('register') }}">
                                {{ $plan->slug === 'enterprise' ? 'Nous contacter' : ($plan->slug === 'premium' ? 'Passer à Premium' : 'Choisir ' . $plan->name) }}
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" style="background:var(--color-surface-2);">
            <div class="wrap">
                <div class="section-head" style="text-align:center;margin-left:auto;margin-right:auto;">
                    <span class="eyebrow">Questions fréquentes</span>
                    <h2 class="section-title" style="margin:0 auto;">Ce que les utilisateurs demandent le plus</h2>
                </div>
                <div class="faq">
                    <details open>
                        <summary>Le mode sans IA est-il vraiment gratuit ?</summary>
                        <div class="faq-body">Oui. Le moteur déterministe de mise en forme est toujours disponible, y compris sur le plan Gratuit, sans jamais consommer de crédits.</div>
                    </details>
                    <details>
                        <summary>Que se passe-t-il si je n'ai pas assez de crédits ?</summary>
                        <div class="faq-body">FORMADOC vous propose d'acheter des crédits ou de continuer sans assistance IA — le traitement déterministe reste toujours accessible gratuitement.</div>
                    </details>
                    <details>
                        <summary>Puis-je annuler mon abonnement à tout moment ?</summary>
                        <div class="faq-body">Oui, l'annulation est possible à tout moment. Votre abonnement reste actif jusqu'à la fin de la période déjà payée, puis bascule vers le plan Gratuit.</div>
                    </details>
                    <details>
                        <summary>Quels formats de fichiers sont acceptés ?</summary>
                        <div class="faq-body">Les fichiers .docx, .doc et .txt jusqu'à 50 Mo. D'autres formats (dont le PDF) pourront être ajoutés ultérieurement.</div>
                    </details>
                    <details>
                        <summary>Mes documents sont-ils transmis à l'IA ?</summary>
                        <div class="faq-body">Non, pas par défaut. Le mode déterministe traite vos documents sans envoi à un service externe. L'assistance IA ne s'active que si vous cochez l'option, et son coût est affiché avant chaque action.</div>
                    </details>
                    <details>
                        <summary>Comment payer avec Orange Money ou MTN MoMo ?</summary>
                        <div class="faq-body">Au moment du paiement, choisissez « Orange Money » ou « MTN MoMo » : vous êtes redirigé vers la passerelle sécurisée (KPay) pour confirmer le règlement depuis votre téléphone. Vous recevez ensuite un reçu par email.</div>
                    </details>
                    <details>
                        <summary>Que deviennent mes fichiers après le traitement ?</summary>
                        <div class="faq-body">Vos fichiers sont supprimés automatiquement 30 jours après le traitement, pour votre confidentialité. Vous pouvez les télécharger à tout moment avant cette échéance.</div>
                    </details>
                    <details>
                        <summary>Comment sont facturés les traitements IA ?</summary>
                        <div class="faq-body">1 crédit = 1 FCFA. Chaque action IA affiche son coût estimé avant exécution ; seul le coût réel est débité après usage. Vous pouvez acheter des crédits à partir de 500 FCFA, sans abonnement.</div>
                    </details>
                    <details>
                        <summary>Le plan Gratuit est-il vraiment gratuit ?</summary>
                        <div class="faq-body">Oui. Cinq documents déterministes par mois, sans carte bancaire, sans limite de temps. Les traitements IA et les fonctionnalités avancées sont payants, mais jamais imposés.</div>
                    </details>
                    <details>
                        <summary>Mes documents sont-ils conservés indéfiniment ?</summary>
                        <div class="faq-body">Non, les fichiers téléversés sont supprimés automatiquement après 30 jours pour votre confidentialité.</div>
                    </details>
                </div>
            </div>
        </section>

        <!-- CTA FINAL -->
        <section>
            <div class="wrap">
                <div class="cta-band">
                    <h2>Prêt à mettre vos documents en forme ?</h2>
                    <p>Cinq documents gratuits par mois, sans carte bancaire requise pour commencer.</p>
                    <div style="display:flex;gap:.7rem;justify-content:center;flex-wrap:wrap;">
                        <a class="btn btn-primary btn-lg" href="{{ auth()->check() ? route('documents.create') : route('register') }}">Créer un compte gratuit</a>
                        <a class="btn btn-secondary btn-lg" href="#tarifs">Explorer les tarifs</a>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- FOOTER -->
    <footer class="landing-footer" id="contact">
        <div class="wrap">
            <div class="footer-grid">
                <div class="footer-brand">
                    <div class="brand"><span class="mark">FD</span> FORMADOC</div>
                    <p>La mise en forme automatique de documents, avec ou sans assistance IA. Basé à Douala, Cameroun.</p>
                </div>
                <div class="footer-col">
                    <h4>Produit</h4>
                    <ul>
                        <li><a href="#fonctionnalites">Fonctionnalités</a></li>
                        <li><a href="#tarifs">Tarifs</a></li>
                        <li><a href="{{ auth()->check() ? route('documents.create') : route('register') }}">Analyser un rapport</a></li>
                        @auth
                            <li><a href="{{ route('chat.index') }}">Assistant IA</a></li>
                        @endauth
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Compte</h4>
                    <ul>
                        <li><a href="{{ route('login') }}">Connexion</a></li>
                        <li><a href="{{ route('register') }}">Créer un compte</a></li>
                        @auth
                            <li><a href="{{ route('account.index') }}">Crédits &amp; abonnement</a></li>
                        @endauth
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="#faq">Questions fréquentes</a></li>
                        <li><a href="{{ route('feedback.form') }}">Votre avis</a></li>
                        <li><a href="{{ route('feedback.form') }}">Contacter le support</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Légal</h4>
                    <ul>
                        <li><a href="{{ route('pages.legal') }}">Mentions légales</a></li>
                        <li><a href="{{ route('pages.terms') }}">Conditions générales</a></li>
                        <li><a href="{{ route('pages.privacy') }}">Politique de confidentialité</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} FORMADOC. Tous droits réservés.</span>
                <div class="footer-payments">
                    <span>Carte bancaire</span><span>KPay</span><span>Orange Money</span><span>MTN MoMo</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Icônes lucide
            if (window.lucide) {
                lucide.createIcons();
            }
            // Thème initial
            const saved = localStorage.getItem('formadoc-theme');
            if (saved === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                const icon = document.querySelector('#themeToggle i');
                if (icon) { icon.setAttribute('data-lucide', 'sun'); lucide.createIcons(); }
            }

            // Menu mobile
            const menuToggle = document.getElementById('landing-menu-toggle');
            const mobileMenu = document.getElementById('landing-mobile-menu');
            if (menuToggle && mobileMenu) {
                const closeMenu = () => {
                    mobileMenu.classList.remove('open');
                    mobileMenu.setAttribute('aria-hidden', 'true');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.setAttribute('aria-label', 'Ouvrir le menu');
                };
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = mobileMenu.classList.toggle('open');
                    mobileMenu.setAttribute('aria-hidden', String(!isOpen));
                    menuToggle.setAttribute('aria-expanded', String(isOpen));
                    menuToggle.setAttribute('aria-label', isOpen ? 'Fermer le menu' : 'Ouvrir le menu');
                });
                mobileMenu.querySelectorAll('a').forEach((a) => a.addEventListener('click', closeMenu));
                document.addEventListener('click', (e) => {
                    if (mobileMenu.classList.contains('open') && !mobileMenu.contains(e.target) && !menuToggle.contains(e.target)) {
                        closeMenu();
                    }
                });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && mobileMenu.classList.contains('open')) { closeMenu(); }
                });
            }
        });
    </script>
</body>
</html>
