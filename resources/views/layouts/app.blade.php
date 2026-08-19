<!DOCTYPE html>
<html lang="fr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'FORMADOC') — Mise en forme automatique de rapports</title>

    {{-- Polices : Inter (corps/titres), JetBrains Mono (libellés), Source Serif 4 (aperçu doc) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&family=Source+Serif+4:wght@400&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

    {{-- Tailwind CDN + design system (gabarit ScholarForm) --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    {{-- Alpine.js (builder visuel de page de garde) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#004ac6",
                        "primary-container": "#2563eb",
                        "primary-fixed": "#dbe1ff",
                        "primary-fixed-dim": "#b4c5ff",
                        "on-primary": "#ffffff",
                        "on-primary-fixed": "#00174b",
                        "on-primary-fixed-variant": "#003ea8",
                        "background": "#f7f9fb",
                        "surface": "#f7f9fb",
                        "surface-dim": "#d8dadc",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f2f4f6",
                        "surface-container": "#eceef0",
                        "surface-container-high": "#e6e8ea",
                        "surface-container-highest": "#e0e3e5",
                        "surface-variant": "#e0e3e5",
                        "on-surface": "#191c1e",
                        "on-surface-variant": "#434655",
                        "secondary": "#505f76",
                        "secondary-container": "#d0e1fb",
                        "on-secondary": "#ffffff",
                        "on-secondary-container": "#54647a",
                        "outline": "#737686",
                        "outline-variant": "#c3c6d7",
                        "inverse-surface": "#2d3133",
                        "inverse-primary": "#b4c5ff",
                        "error": "#ba1a1a",
                        "error-container": "#ffdad6",
                        "on-error": "#ffffff",
                        "on-error-container": "#93000a"
                    },
                    borderRadius: {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    spacing: {
                        "gutter": "24px",
                        "margin-desktop": "40px",
                        "container-max": "1280px",
                        "base": "8px",
                        "margin-mobile": "16px"
                    },
                    fontFamily: {
                        "body-md": ["Inter"],
                        "h1": ["Inter"],
                        "h1-mobile": ["Inter"],
                        "h2": ["Inter"],
                        "caption": ["Inter"],
                        "label-mono": ["JetBrains Mono"],
                        "doc-preview": ["\"Source Serif 4\"", "serif"]
                    },
                    fontSize: {
                        "caption": ["13px", { lineHeight: "1.4", fontWeight: "500" }],
                        "body-md": ["16px", { lineHeight: "1.6", fontWeight: "400" }],
                        "h2": ["24px", { lineHeight: "1.3", fontWeight: "600" }],
                        "h1-mobile": ["28px", { lineHeight: "1.2", fontWeight: "700" }],
                        "label-mono": ["12px", { lineHeight: "1.0", letterSpacing: "0.05em", fontWeight: "500" }],
                        "h1": ["36px", { lineHeight: "1.2", letterSpacing: "-0.02em", fontWeight: "700" }],
                        "doc-preview": ["18px", { lineHeight: "1.8", fontWeight: "400" }]
                    }
                }
            }
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .material-symbols-outlined.fill {
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .loading-track {
            background-color: #e0e3e5;
            height: 4px;
            border-radius: 9999px;
            overflow: hidden;
            position: relative;
        }
        .loading-fill {
            position: absolute;
            top: 0;
            left: -100%;
            height: 100%;
            width: 50%;
            background: linear-gradient(90deg, transparent, #004ac6, #b4c5ff, transparent);
            animation: indeterminate 1.5s infinite linear;
            border-radius: 9999px;
        }
        @keyframes indeterminate {
            0% { left: -100%; width: 50%; }
            50% { width: 30%; }
            100% { left: 100%; width: 50%; }
        }
        .pulse-icon {
            animation: gentle-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes gentle-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: .7; transform: scale(0.95); }
        }
    </style>
</head>
<body class="bg-background text-on-surface font-body-md text-body-md min-h-screen flex flex-col antialiased">

    {{-- TopNavBar --}}
    <header class="fixed top-0 w-full z-50 flex justify-between items-center px-margin-mobile md:px-margin-desktop h-16 bg-surface/80 backdrop-blur-md shadow-sm">
        <div class="flex items-center gap-4">
            <a href="/" class="font-h2 text-h2 font-bold text-primary">FORMADOC</a>
            <nav class="hidden md:flex gap-6 items-center">
                <a class="font-body-md text-body-md text-secondary hover:text-primary hover:bg-surface-container-high px-3 py-2 rounded transition-colors"
                   href="{{ route('documents.create') }}">Analyser un rapport</a>
                <a class="font-body-md text-body-md text-secondary hover:text-primary hover:bg-surface-container-high px-3 py-2 rounded transition-colors"
                   href="{{ route('feedback.form') }}">Votre avis</a>
            </nav>
        </div>
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('chat.index') }}" class="text-primary hover:bg-surface-container-high p-2 rounded-full transition-colors"
                   aria-label="Chat IA">
                    <span class="material-symbols-outlined">chat</span>
                </a>
                <a href="{{ route('account.index') }}" class="text-primary hover:bg-surface-container-high p-2 rounded-full transition-colors"
                   aria-label="Mon compte">
                    <span class="material-symbols-outlined">account_circle</span>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-secondary hover:text-error hover:bg-surface-container-high p-2 rounded-full transition-colors"
                            aria-label="Se déconnecter">
                        <span class="material-symbols-outlined">logout</span>
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="font-label-mono text-label-mono uppercase tracking-wider text-secondary hover:text-primary px-3 py-2 rounded transition-colors">
                    Connexion
                </a>
                <a href="{{ route('register') }}" class="font-label-mono text-label-mono uppercase tracking-wider text-on-primary bg-primary hover:bg-primary-container px-4 py-2 rounded-lg transition-colors">
                    Inscription
                </a>
            @endauth
        </div>
    </header>

    {{-- Contenu principal (pt-16 compense la barre de navigation fixe) --}}
    <main class="flex-1 pt-16">
        @yield('content')
    </main>

    {{-- Pied de page --}}
    <footer class="w-full py-gutter px-margin-mobile md:px-margin-desktop flex flex-col md:flex-row justify-between items-center bg-surface-container-lowest border-t border-outline-variant mt-auto">
        <p class="font-caption text-caption text-secondary mb-4 md:mb-0">
            © {{ date('Y') }} FORMADOC — Mise en forme automatique de rapports académiques
        </p>
        <div class="flex gap-6">
            <a class="font-caption text-caption text-outline hover:text-primary underline transition-colors"
               href="{{ route('feedback.form') }}">Envoyer un avis</a>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
