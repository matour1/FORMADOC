<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2b3f66">
    <title>@yield('title', 'FORMADOC') — Mise en forme automatique de rapports</title>

    {{-- Polices : Newsreader (display), Inter (corps), IBM Plex Mono (libellés) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- Design system (extrait de formadoc-template.html) + Tailwind pour les pages legacy --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    {{-- Alpine.js (builder visuel de page de garde) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    {{-- Icônes lucide (comme le template) --}}
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body class="app">

    {{-- Sidebar (desktop : sticky ; mobile : hors écran + overlay) --}}
    <aside class="sidebar" id="app-sidebar">
        <a href="/" class="sidebar-brand">
            <span class="mark">FD</span>
            FORMADOC
        </a>

        <span class="sidebar-section-label">Documents</span>
        <nav class="sidebar-nav">
            <a href="{{ route('documents.create') }}" class="{{ request()->routeIs('documents.*') ? 'active' : '' }}">
                <i data-lucide="file-up"></i> Analyser un rapport
            </a>
            @auth
                <a href="{{ route('chat.index') }}" class="{{ request()->routeIs('chat.*') ? 'active' : '' }}">
                    <i data-lucide="message-circle"></i> Assistant IA
                </a>
                <a href="{{ route('account.index') }}" class="{{ request()->routeIs('account.*') ? 'active' : '' }}">
                    <i data-lucide="user"></i> Mon compte
                </a>
            @endauth
        </nav>

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
                <div class="user-info" style="flex:1">
                    <a href="{{ route('login') }}" class="btn btn-secondary btn-sm btn-block">Connexion</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm btn-block" style="margin-top:.4rem">Inscription</a>
                </div>
            @endauth
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    {{-- Zone principale --}}
    <div class="main">
        {{-- Navbar --}}
        <header class="navbar">
            <div class="navbar-left">
                <button class="navbar-menu-toggle" type="button" aria-label="Menu" id="navbar-menu-toggle">
                    <i data-lucide="menu"></i>
                </button>
                <div class="navbar-search">
                    <i data-lucide="search" style="width:14px;height:14px"></i>
                    <input type="search" placeholder="Rechercher…" aria-label="Recherche">
                    <span class="search-label mono" style="font-size:.68rem">Ctrl K</span>
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
                <button class="theme-toggle" type="button" aria-label="Basculer le thème" id="theme-toggle">
                    <i data-lucide="moon"></i>
                </button>
                @auth
                    <a href="{{ route('account.index') }}" class="avatar-nav" title="Mon compte">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}" class="inline" style="display:inline">
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
        <div class="toast-container" id="toast-container">
            @if (session('success'))
                <div class="toast success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="toast error">{{ session('error') }}</div>
            @endif
            @if (session('warning'))
                <div class="toast warning">{{ session('warning') }}</div>
            @endif
            @if (session('info'))
                <div class="toast">{{ session('info') }}</div>
            @endif
        </div>

        {{-- Contenu principal --}}
        <main class="page-container">
            {{-- Erreurs de validation --}}
            @if ($errors->any())
                <div class="banner banner-danger">
                    <i data-lucide="alert-circle"></i>
                    <div>
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
                <a href="{{ route('feedback.form') }}" class="mono" style="font-size:.72rem;color:var(--color-text-muted);text-decoration:underline">Envoyer un avis</a>
            </div>
        </footer>
    </div>

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
        });
        // Toggle thème
        document.getElementById('theme-toggle')?.addEventListener('click', () => {
            const root = document.documentElement;
            const isDark = root.getAttribute('data-theme') === 'dark';
            root.setAttribute('data-theme', isDark ? 'light' : 'dark');
            localStorage.setItem('formadoc-theme', isDark ? 'light' : 'dark');
            const icon = document.querySelector('#theme-toggle i');
            if (icon) { icon.setAttribute('data-lucide', isDark ? 'moon' : 'sun'); lucide.createIcons(); }
        });
        // Sidebar mobile
        document.getElementById('navbar-menu-toggle')?.addEventListener('click', () => {
            document.querySelector('.sidebar')?.classList.toggle('open');
            document.querySelector('.sidebar-overlay')?.classList.toggle('open');
        });
        document.querySelector('.sidebar-overlay')?.addEventListener('click', () => {
            document.querySelector('.sidebar')?.classList.remove('open');
            document.querySelector('.sidebar-overlay')?.classList.remove('open');
        });
        // Fermeture auto des toasts
        setTimeout(() => {
            document.querySelectorAll('.toast').forEach(t => t.remove());
        }, 6000);
    </script>

    @stack('scripts')
</body>
</html>

