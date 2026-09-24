<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Erreur — FORMADOC')</title>
    {{-- Meme favicon que le reste du site. Un SVG en data-URI affichait les
         lettres « FD » dans une police systeme : sur un 404, l'onglet ne
         ressemblait plus au produit et l'erreur semblait venir d'ailleurs. --}}
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/logo-mark.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
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
