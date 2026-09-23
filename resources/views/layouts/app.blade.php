<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>@yield('title', 'FORMADOC') — Mise en forme automatique de rapports</title>

    {{-- Polices : Newsreader (titres), Plus Jakarta Sans (corps), IBM Plex Mono (données) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- Design system (extrait de formadoc-template.html) + Tailwind pour les pages legacy --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    {{-- Alpine.js (interactivité de l'interface) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    {{-- Icônes lucide (comme le template) --}}
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body class="app">

    <a class="skip-link" href="#mainContent">Aller au contenu principal</a>

    {{-- Sidebar (desktop : sticky ; mobile : hors écran + overlay) --}}
    <aside class="sidebar" id="app-sidebar" aria-label="Navigation principale">
        <a href="/" class="sidebar-brand">
            <span class="mark">FD</span>
            FORMADOC
        </a>

        <span class="sidebar-section-label">Espace de travail</span>
        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i data-lucide="layout-grid"></i> Tableau de bord
            </a>
            <a href="{{ route('documents.index') }}" class="{{ request()->routeIs('documents.index') ? 'active' : '' }}">
                <i data-lucide="file-text"></i> Mes documents
            </a>
            <a href="{{ route('documents.create') }}" class="{{ request()->routeIs('documents.create') || request()->routeIs('documents.upload') || request()->routeIs('documents.show') || request()->routeIs('documents.processing') || request()->routeIs('documents.export') ? 'active' : '' }}">
                <i data-lucide="file-plus-2"></i> Nouveau document
            </a>
            <a href="{{ route('templates.index') }}" class="{{ request()->routeIs('templates.index') ? 'active' : '' }}">
                <i data-lucide="layout-template"></i> Modèles
            </a>
            <a href="{{ route('templates.compare') }}" class="{{ request()->routeIs('templates.compare') ? 'active' : '' }}">
                <i data-lucide="columns-2"></i> Comparer des gabarits
            </a>
            @auth
                <a href="{{ route('chat.index') }}" class="{{ request()->routeIs('chat.*') ? 'active' : '' }}">
                    <i data-lucide="message-circle"></i> Assistant IA
                </a>
            @endauth
        </nav>

        @auth
            <span class="sidebar-section-label">Compte</span>
            <nav class="sidebar-nav">
                <a href="{{ route('account.index') }}" class="{{ request()->routeIs('account.index') || request()->routeIs('subscriptions.*') || request()->routeIs('credits.*') || request()->routeIs('invoices.*') ? 'active' : '' }}">
                    <i data-lucide="user"></i> Crédits &amp; abonnement
                </a>
                <a href="{{ route('account.settings') }}" class="{{ request()->routeIs('account.settings*') ? 'active' : '' }}">
                    <i data-lucide="settings"></i> Paramètres
                </a>
            </nav>
        @endauth

        <span class="sidebar-section-label">Aide</span>
        <nav class="sidebar-nav">
            <a href="{{ route('feedback.form') }}" class="{{ request()->routeIs('feedback.*') ? 'active' : '' }}">
                <i data-lucide="heart"></i> Votre avis
            </a>
        </nav>

        <div class="sidebar-footer">
            @auth
                <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                <div class="user-info">
                    <div class="name">{{ auth()->user()->name }}</div>
                    <div class="email">{{ auth()->user()->email }}</div>
                </div>
            @else
                <div class="user-info" style="flex:1;display:flex;flex-direction:column;gap:.4rem">
                    <a href="{{ route('login') }}" class="btn btn-secondary btn-sm btn-block">Connexion</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm btn-block">Inscription</a>
                </div>
            @endauth
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    {{-- Zone principale --}}
    <div class="main" id="mainContent">
        {{-- Navbar --}}
        <header class="navbar">
            <div class="navbar-left">
                <button class="navbar-menu-toggle" type="button" aria-label="Ouvrir le menu de navigation" aria-expanded="false" id="navbar-menu-toggle">
                    <i data-lucide="menu"></i>
                </button>
                <div class="navbar-search" role="search">
                    <i data-lucide="search" style="width:15px;height:15px"></i>
                    <label for="navbarSearchInput" class="sr-only">Rechercher</label>
                    <input id="navbarSearchInput" type="search" placeholder="Rechercher…" autocomplete="off">
                    <span class="search-label mono" style="font-size:.66rem">Ctrl K</span>
                </div>
            </div>
            <div class="navbar-actions">
                @auth
                    <a href="{{ route('account.index') }}" class="credits-badge {{ auth()->user()->credits_balance < 100 ? 'low' : '' }}"
                       title="Crédits disponibles — 1 crédit = 1 FCFA">
                        <i data-lucide="coins" style="width:14px;height:14px"></i>
                        <span class="credits-label">{{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }}</span>
                    </a>
                @endauth
                <button class="theme-toggle" type="button" aria-label="Basculer le thème clair/sombre" id="theme-toggle" aria-pressed="false">
                    <i data-lucide="moon" style="width:17px;height:17px"></i>
                </button>
                @auth
                    <a href="{{ route('account.index') }}" class="avatar-nav" title="Mon compte" aria-label="Mon compte">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}" class="inline" style="display:inline" onsubmit="return confirm('Se déconnecter ?');">
                        @csrf
                        <button type="submit" class="icon-btn" aria-label="Se déconnecter" title="Déconnexion">
                            <i data-lucide="log-out" style="width:17px;height:17px"></i>
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary btn-sm">Connexion</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Inscription</a>
                @endauth
            </div>
        </header>

        {{-- Toasts (flash messages) --}}
        <div class="toast-container" id="toast-container" aria-live="polite">
            @if (session('success'))
                <div class="toast toast-success">
                    <i data-lucide="check-circle" style="width:17px;height:17px"></i>
                    <div style="flex:1">{{ session('success') }}</div>
                    <button type="button" class="toast-close" aria-label="Fermer">&times;</button>
                </div>
            @endif
            @if (session('error'))
                <div class="toast toast-error">
                    <i data-lucide="alert-circle" style="width:17px;height:17px"></i>
                    <div style="flex:1">{{ session('error') }}</div>
                    <button type="button" class="toast-close" aria-label="Fermer">&times;</button>
                </div>
            @endif
            @if (session('warning'))
                <div class="toast toast-warning">
                    <i data-lucide="alert-triangle" style="width:17px;height:17px"></i>
                    <div style="flex:1">{{ session('warning') }}</div>
                    <button type="button" class="toast-close" aria-label="Fermer">&times;</button>
                </div>
            @endif
            @if (session('info'))
                <div class="toast">
                    <i data-lucide="info" style="width:17px;height:17px"></i>
                    <div style="flex:1">{{ session('info') }}</div>
                    <button type="button" class="toast-close" aria-label="Fermer">&times;</button>
                </div>
            @endif
            @if (session('pending_cost'))
                @php $pendingCost = session('pending_cost'); @endphp
                <div class="toast toast-warning">
                    <i data-lucide="hourglass" style="width:17px;height:17px"></i>
                    <div style="flex:1">
                        @if (is_array($pendingCost))
                            Coût estimé : {{ $pendingCost['credits'] ?? '?' }} crédit(s) — confirmez l'envoi.
                        @else
                            {{ $pendingCost }}
                        @endif
                    </div>
                    <button type="button" class="toast-close" aria-label="Fermer">&times;</button>
                </div>
            @endif
        </div>

        {{-- Contenu principal --}}
        <main class="page-container">
            {{-- Erreurs de validation (indisponible sur les pages d'erreur) --}}
            @if (isset($errors) && $errors->any())
                <div class="banner banner-danger" role="alert">
                    <i data-lucide="alert-circle"></i>
                    <div>
                        <strong>Veuillez corriger les erreurs suivantes :</strong>
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            @yield('content')
        </main>

        {{-- Pied de page --}}
        <footer class="page-container" style="padding-top:0">
            <div style="border-top:1px solid var(--color-border);padding-top:1.25rem;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <p class="mono" style="font-size:.72rem;color:var(--color-text-muted)">
                    © {{ date('Y') }} FORMADOC — Mise en forme automatique de rapports académiques
                </p>
                <div style="display:flex;gap:1rem;align-items:center">
                    <a href="{{ route('feedback.form') }}" class="mono" style="font-size:.72rem;color:var(--color-text-muted);text-decoration:underline">Envoyer un avis</a>
                    @auth
                        <a href="{{ route('chat.index') }}" class="mono" style="font-size:.72rem;color:var(--color-text-muted);text-decoration:underline">Assistant IA</a>
                    @endauth
                </div>
            </div>
        </footer>
    </div>

    @stack('scripts')

    <script>
        // Initialisation des icônes lucide (si chargées)
        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide) {
                lucide.createIcons();
            }
            // Thème au chargement
            const saved = localStorage.getItem('formadoc-theme');
            if (saved === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.querySelector('#theme-toggle i')?.setAttribute('data-lucide', 'sun');
            }
            // Icônes des toasts (rendus après DOMContentLoaded si lucide différé)
            if (window.lucide) { lucide.createIcons(); }
            // Auto-fermeture des toasts (6s) avec transition douce
            document.querySelectorAll('.toast').forEach(t => {
                const dismiss = () => {
                    t.style.opacity = '0';
                    t.style.transform = 'translateX(40px)';
                    setTimeout(() => t.remove(), 300);
                };
                setTimeout(dismiss, 6000);
                t.querySelector('.toast-close')?.addEventListener('click', dismiss);
            });
        });
        // Toggle thème
        document.getElementById('theme-toggle')?.addEventListener('click', () => {
            const root = document.documentElement;
            const isDark = root.getAttribute('data-theme') === 'dark';
            root.setAttribute('data-theme', isDark ? 'light' : 'dark');
            localStorage.setItem('formadoc-theme', isDark ? 'light' : 'dark');
            const icon = document.querySelector('#theme-toggle i');
            if (icon) { icon.setAttribute('data-lucide', isDark ? 'moon' : 'sun'); if (window.lucide) lucide.createIcons(); }
        });
        // Sidebar mobile (avec overlay + Escape)
        const menuToggle = document.getElementById('navbar-menu-toggle');
        const sidebarEl = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        if (menuToggle && sidebarEl && sidebarOverlay) {
            const closeSidebar = () => {
                sidebarEl.classList.remove('open');
                sidebarOverlay.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
            };
            menuToggle.addEventListener('click', () => {
                const isOpen = sidebarEl.classList.toggle('open');
                sidebarOverlay.classList.toggle('open', isOpen);
                menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            sidebarOverlay.addEventListener('click', closeSidebar);
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
        }
        // Recherche globale : filtre les éléments .doc-row, .chat-item, .template-card de la page courante
        const navbarSearch = document.getElementById('navbarSearchInput');
        if (navbarSearch) {
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    navbarSearch.focus();
                    navbarSearch.select();
                }
                if (e.key === 'Escape' && document.activeElement === navbarSearch) {
                    navbarSearch.blur();
                }
            });
            navbarSearch.addEventListener('input', () => {
                const q = navbarSearch.value.trim().toLowerCase();
                document.querySelectorAll('.doc-row, .chat-item, .template-card').forEach(el => {
                    const hay = (el.textContent || '').toLowerCase();
                    el.style.display = q === '' || hay.includes(q) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>

