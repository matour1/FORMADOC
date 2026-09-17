{{--
    Layout de l'espace d'administration.

    Délibérément SÉPARÉ de `layouts.app` : la navigation utilisateur (dashboard,
    documents, assistant) n'a pas sa place dans un écran d'exploitation, et
    inversement. Mélanger les deux produirait un menu où l'administrateur ne
    saurait plus dans quel espace il se trouve.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Administration') · FORMADOC</title>
    {{-- Mêmes entrées que `layouts.app` : les nommer autrement ferait échouer le
         build Vite (`Unable to locate file in Vite manifest`). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background:var(--color-surface-2);margin:0">

    <header style="background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:.75rem 1.5rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
        <a href="{{ route('admin.index') }}" style="text-decoration:none;display:flex;align-items:center;gap:.5rem">
            <strong style="font-size:1rem">FORMADOC</strong>
            <span class="eyebrow" style="margin:0;background:var(--color-surface-2);padding:.15rem .5rem;border-radius:4px">
                Administration
            </span>
        </a>

        <nav style="display:flex;gap:1.25rem;flex-wrap:wrap;font-size:.88rem">
            <a href="{{ route('admin.index') }}"
               style="{{ request()->routeIs('admin.index') ? 'font-weight:700;color:var(--color-primary)' : 'color:var(--color-text-secondary)' }}">
                Vue d'ensemble
            </a>
            <a href="{{ route('admin.billing') }}"
               style="{{ request()->routeIs('admin.billing') ? 'font-weight:700;color:var(--color-primary)' : 'color:var(--color-text-secondary)' }}">
                Rentabilité
            </a>
            <a href="{{ route('admin.usage') }}"
               style="{{ request()->routeIs('admin.usage') ? 'font-weight:700;color:var(--color-primary)' : 'color:var(--color-text-secondary)' }}">
                Usage IA
            </a>
            <a href="{{ route('admin.classification') }}"
               style="{{ request()->routeIs('admin.classification') ? 'font-weight:700;color:var(--color-primary)' : 'color:var(--color-text-secondary)' }}">
                Classification
            </a>
            <a href="{{ route('admin.documents') }}"
               style="{{ request()->routeIs('admin.documents') ? 'font-weight:700;color:var(--color-primary)' : 'color:var(--color-text-secondary)' }}">
                Documents
            </a>
        </nav>

        <div style="margin-left:auto;display:flex;align-items:center;gap:1rem;font-size:.85rem">
            <span style="color:var(--color-text-muted)">{{ auth()->user()?->email }}</span>
            <a href="{{ route('dashboard') }}" style="color:var(--color-text-secondary)">
                Espace utilisateur
            </a>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:1.75rem 1.5rem 3rem">
        @yield('content')
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
