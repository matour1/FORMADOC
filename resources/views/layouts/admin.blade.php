{{--
    Layout de l'espace d'administration.

    Délibérément SÉPARÉ de `layouts.app` : la navigation utilisateur (dashboard,
    documents, assistant) n'a pas sa place dans un écran d'exploitation, et
    inversement. Mélanger les deux produirait un menu où l'administrateur ne
    saurait plus dans quel espace il se trouve.

    En revanche, il n'est plus écrit en styles inline. La version précédente
    réimplémentait sa propre barre et son propre en-tête : les mêmes éléments que
    `.sidebar`, `.sidebar-nav` et `.navbar`, mais avec des valeurs figées. Deux
    conséquences : le thème sombre ne descendait pas jusqu'à sa mise en page, et
    toute évolution du design system le laissait derrière. Il consomme désormais
    les composants partagés -- une seule définition à corriger, et l'espace
    d'exploitation reste visuellement dans le produit.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/logo-mark.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
    <title>@yield('title', 'Administration') · FORMADOC</title>
    {{-- Polices : sans ce bloc, `--font-display` et `--font-body` retombent sur
         les polices système et l'admin ne ressemble plus au reste du produit.
         Le layout est séparé, pas la typographie. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    {{-- Mêmes entrées que `layouts.app` : les nommer autrement ferait échouer le
         build Vite (`Unable to locate file in Vite manifest`). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body class="app">

    <a class="skip-link" href="#adminContent">Aller au contenu principal</a>

    <aside class="sidebar" id="admin-sidebar" aria-label="Navigation d'administration">
        <a href="{{ route('admin.index') }}" class="sidebar-brand">
            <span class="mark">
                <img src="{{ asset('images/logo-mark.png') }}" alt="" width="34" height="34">
            </span>
            FORMADOC
        </a>

        <span class="sidebar-section-label">Exploitation</span>
        <nav class="sidebar-nav">
            <a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.index') ? 'active' : '' }}">
                <i data-lucide="gauge"></i> Vue d'ensemble
            </a>
            <a href="{{ route('admin.billing') }}" class="{{ request()->routeIs('admin.billing') ? 'active' : '' }}">
                <i data-lucide="banknote"></i> Rentabilité
            </a>
            <a href="{{ route('admin.usage') }}" class="{{ request()->routeIs('admin.usage') ? 'active' : '' }}">
                <i data-lucide="activity"></i> Usage IA
            </a>
            <a href="{{ route('admin.classification') }}" class="{{ request()->routeIs('admin.classification') ? 'active' : '' }}">
                <i data-lucide="tags"></i> Classification
            </a>
            <a href="{{ route('admin.documents') }}" class="{{ request()->routeIs('admin.documents') ? 'active' : '' }}">
                <i data-lucide="file-text"></i> Documents
            </a>
        </nav>

        <span class="sidebar-section-label">Pilotage</span>
        <nav class="sidebar-nav">
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                <i data-lucide="users"></i> Comptes
            </a>
            <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'active' : '' }}">
                <i data-lucide="sliders-horizontal"></i> Configuration
            </a>
        </nav>

        {{-- Sortie vers le reste du produit. Sans ces deux liens, l'espace
             d'exploitation était un cul-de-sac : le seul retour possible était
             le bouton « précédent » du navigateur. --}}
        <span class="sidebar-section-label">Autres espaces</span>
        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}">
                <i data-lucide="layout-grid"></i> Espace utilisateur
            </a>
            <a href="/">
                <i data-lucide="globe"></i> Site public
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
            <div class="user-info">
                <div class="name">{{ auth()->user()->name }}</div>
                <div class="email">{{ auth()->user()->email }}</div>
            </div>
        </div>
    </aside>
    <div class="sidebar-overlay" id="admin-sidebar-overlay"></div>

    <div class="main" id="adminContent">
        <header class="navbar">
            <div class="navbar-left">
                <button class="navbar-menu-toggle" type="button"
                        aria-label="Ouvrir le menu de navigation" aria-expanded="false"
                        id="admin-menu-toggle">
                    <i data-lucide="menu"></i>
                </button>
                <span class="eyebrow" style="margin:0">Exploitation</span>
            </div>
            <div class="navbar-actions">
                {{-- Repère permanent : cet espace n'a pas les mêmes droits que le
                     reste du produit, il doit être impossible de s'y croire par
                     erreur. --}}
                <span class="role-tag">Admin</span>
                <button class="theme-toggle" type="button"
                        aria-label="Basculer le thème clair/sombre" aria-pressed="false"
                        id="admin-theme-toggle">
                    <i data-lucide="moon" style="width:17px;height:17px"></i>
                </button>
            </div>
        </header>

        <main class="page-container">
            {{-- Erreurs de validation.
                 Indispensables depuis que l'espace d'exploitation n'est plus en
                 lecture seule : une saisie refusée (marge hors bornes, devise mal
                 formée, motif d'ajustement trop court) renvoyait le formulaire SANS
                 aucune explication. La valeur semblait ignorée, et rien n'indiquait
                 s'il fallait corriger la saisie ou si l'enregistrement avait échoué.

                 L'intitulé est resté neutre (« Action refusée ») : le layout sert
                 plusieurs formulaires, et « Réglage refusé » ne décrivait plus rien
                 d'utile sur la fiche d'un compte. --}}
            @if (isset($errors) && $errors->any())
                <div class="banner banner-danger" role="alert">
                    <i data-lucide="alert-circle"></i>
                    <div>
                        <strong>Action refusée :</strong>
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    <script>
        /* Élément d'icône du bouton de thème.
           Même piège que dans `layouts.app` : lucide remplace le `<i>` par un
           `<svg>` au premier rendu, donc un sélecteur `i` ne trouve plus rien et
           l'icône restait bloquée sur la lune. `[data-lucide]` couvre les deux. */
        const iconeTheme = () => document.querySelector('#admin-theme-toggle [data-lucide]');

        document.addEventListener('DOMContentLoaded', () => {
            // --- Thème -------------------------------------------------------
            // Même clé de stockage que `layouts.app` : un thème choisi dans
            // l'espace utilisateur doit se retrouver ici, et inversement. Deux
            // clés distinctes donneraient deux thèmes pour un même compte.
            const root = document.documentElement;
            if (localStorage.getItem('formadoc-theme') === 'dark') {
                root.setAttribute('data-theme', 'dark');
                iconeTheme()?.setAttribute('data-lucide', 'sun');
            }

            if (window.lucide) { lucide.createIcons(); }

            document.getElementById('admin-theme-toggle')?.addEventListener('click', () => {
                const isDark = root.getAttribute('data-theme') === 'dark';
                root.setAttribute('data-theme', isDark ? 'light' : 'dark');
                localStorage.setItem('formadoc-theme', isDark ? 'light' : 'dark');
                iconeTheme()?.setAttribute('data-lucide', isDark ? 'moon' : 'sun');
                if (window.lucide) { lucide.createIcons(); }
            });

            // --- Repli de la sidebar (mobile) --------------------------------
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('admin-sidebar-overlay');
            const toggle = document.getElementById('admin-menu-toggle');

            const fermer = () => {
                sidebar?.classList.remove('open');
                overlay?.classList.remove('open');
                toggle?.setAttribute('aria-expanded', 'false');
            };

            toggle?.addEventListener('click', () => {
                const ouvert = sidebar?.classList.toggle('open');
                overlay?.classList.toggle('open', !!ouvert);
                toggle.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
            });

            overlay?.addEventListener('click', fermer);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') { fermer(); }
            });
        });
    </script>
</body>
</html>
