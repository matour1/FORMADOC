<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Erreur — FORMADOC')</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%230f172a'/><text x='50' y='68' font-size='52' text-anchor='middle' fill='white' font-family='Georgia'>FD</text></svg>">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body class="app">
    <a class="skip-link" href="#mainContent">Aller au contenu principal</a>

    <main id="mainContent" class="err-page-min" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;">
        @yield('content')
    </main>
</body>
</html>
