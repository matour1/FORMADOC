{{--
    Page publique de règlement d'un lien de paiement.

    Délibérément AUTONOME et non basée sur `layouts.app` : le destinataire d'un
    lien n'a pas de compte FORMADOC, donc la sidebar de navigation lui proposerait
    « Tableau de bord », « Mes documents », « Assistant IA » — des entrées qui le
    mèneraient toutes à une page de connexion. Une page de règlement doit se suffire
    à elle-même : le montant, le bouton, et rien qui évoque un espace auquel
    l'utilisateur n'a pas accès.

    Aucune sidebar, donc, et aucun lien vers l'espace utilisateur. Seul le retour
    au site public est proposé.
--}}
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    {{-- `noindex` : une page de règlement à jeton unique n'a rien à faire dans un
         moteur de recherche. Un lien indexé deviendrait public. --}}
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/logo-mark.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
    <title>Règlement — FORMADOC</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest" defer></script>
</head>
<body style="background:var(--color-bg);min-height:100vh">

    <a class="skip-link" href="#paiement">Aller au contenu principal</a>

    <main id="paiement">
        @yield('content')
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide) { lucide.createIcons(); }
        });
    </script>
</body>
</html>

<div class="page-container" style="max-width:640px;padding-top:3rem">
    <div style="text-align:center;margin-bottom:2rem">
        <img src="{{ asset('images/logo-full@2x.png') }}" alt="FORMADOC"
             style="height:44px;width:auto;display:block;margin:0 auto 1rem">
    </div>

    @if (session('success'))
        <div class="banner banner-success">
            <i data-lucide="check-circle"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif
    @if (session('error'))
        <div class="banner banner-danger">
            <i data-lucide="alert-circle"></i>
            <div>{{ session('error') }}</div>
        </div>
    @endif
    @if (session('info'))
        <div class="banner banner-info">
            <i data-lucide="info"></i>
            <div>{{ session('info') }}</div>
        </div>
    @endif

    <div class="card">
        @if ($lien->status === 'paid')
            {{-- Lien déjà réglé : on le dit clairement plutôt que de laisser un
                 bouton actif. Un second règlement du même lien verserait des
                 crédits déjà versés, ou échouerait sans explication. --}}
            <div style="text-align:center;padding:1rem 0">
                <i data-lucide="badge-check" style="width:42px;height:42px;color:var(--color-success)"></i>
                <h1 style="font-size:1.35rem;margin:.9rem 0 .4rem">Paiement déjà enregistré</h1>
                <p style="color:var(--color-text-secondary);font-size:.9rem;margin:0">
                    Ce lien a été réglé le {{ $lien->paid_at?->format('d/m/Y à H:i') }}.
                    Aucune action n'est nécessaire.
                </p>
            </div>
        @elseif ($lien->status === 'cancelled')
            <div style="text-align:center;padding:1rem 0">
                <i data-lucide="ban" style="width:42px;height:42px;color:var(--color-text-muted)"></i>
                <h1 style="font-size:1.35rem;margin:.9rem 0 .4rem">Lien annulé</h1>
                <p style="color:var(--color-text-secondary);font-size:.9rem;margin:0">
                    Ce lien de paiement a été annulé. Contactez votre interlocuteur pour en obtenir un nouveau.
                </p>
            </div>
        @elseif ($expire)
            <div style="text-align:center;padding:1rem 0">
                <i data-lucide="clock" style="width:42px;height:42px;color:var(--color-warning)"></i>
                <h1 style="font-size:1.35rem;margin:.9rem 0 .4rem">Lien expiré</h1>
                <p style="color:var(--color-text-secondary);font-size:.9rem;margin:0">
                    Ce lien était valable jusqu'au {{ $lien->expires_at?->format('d/m/Y à H:i') }}.
                    Contactez votre interlocuteur pour le prolonger ou en obtenir un nouveau.
                </p>
            </div>
        @else
            {{-- Montant et objet affichés AVANT tout bouton : un lien opaque qui
                 demande un paiement sans dire pour quoi n'inspire pas confiance, et
                 c'est le premier motif d'abandon. --}}
            <div style="text-align:center;margin-bottom:1.75rem">
                <span class="eyebrow">Montant à régler</span>
                <div style="font-size:2.5rem;font-weight:700;font-family:var(--font-body);letter-spacing:-.02em"
                     class="tabular">
                    {{ number_format($lien->amount_fcfa, 0, ',', ' ') }}
                    <span style="font-size:1rem;font-weight:400;color:var(--color-text-muted)">
                        {{ $lien->currency }}
                    </span>
                </div>

                @if ($lien->description)
                    <p style="color:var(--color-text-secondary);font-size:.92rem;margin-top:.6rem">
                        {{ $lien->description }}
                    </p>
                @endif

                <p class="mono" style="font-size:.75rem;color:var(--color-text-muted);margin-top:1rem">
                    {{ number_format($lien->credits, 0, ',', ' ') }} crédits · valable jusqu'au
                    {{ $lien->expires_at?->format('d/m/Y') }}
                </p>
            </div>

            @if ($lien->mode === 'online')
                {{-- Le moyen de paiement est choisi ICI, avant la redirection.

                     **Pourquoi ne pas laisser la passerelle choisir.** Chaque
                     fournisseur a son propre widget : il faut décider avant de
                     rediriger. Afficher les moyens réellement disponibles (le
                     registre écarte ceux qui sont désactivés, masqués ou sans clé
                     d'API) évite d'envoyer le client vers un fournisseur qui ne
                     répond pas, sans recours.

                     Un seul moyen disponible : le bouton reste unique, sans
                     sélecteur. Imposer un choix à une option est une friction
                     inutile, et cela retirerait l'information « c'est la seule
                     voie possible ». --}}
                @php
                    $moyens = $moyens ?? [];
                    $plusieurs = count($moyens) > 1;
                @endphp

                @if ($moyens === [])
                    {{-- Aucun moyen : le dire, plutôt que d'afficher un bouton mort.
                         C'est le cas quand les clés d'API manquent ou que tout a été
                         désactivé depuis l'administration. --}}
                    <div class="banner banner-warning" style="margin:0">
                        <i data-lucide="alert-triangle"></i>
                        <div>
                            <strong>Aucun moyen de paiement en ligne disponible</strong>
                            <div style="font-size:.85rem;margin-top:.25rem">
                                Contactez votre interlocuteur pour convenir d'un autre mode de
                                règlement.
                            </div>
                        </div>
                    </div>
                @else
                    @if ($plusieurs)
                        <p style="font-size:.82rem;color:var(--color-text-secondary);margin:0 0 .5rem">
                            Choisissez votre moyen de paiement :
                        </p>
                    @endif

                    <form method="POST" action="{{ route('payment-link.payer', $lien->token) }}">
                        @csrf

                        @if ($plusieurs)
                            <div style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem">
                                @foreach ($moyens as $cle => $moyen)
                                    <label for="gw-{{ $cle }}"
                                        style="display:flex;align-items:flex-start;gap:.6rem;padding:.7rem .85rem;border:1px solid var(--color-border);border-radius:8px;cursor:pointer">
                                        <input type="radio" name="gateway" id="gw-{{ $cle }}"
                                            value="{{ $cle }}" @checked($loop->first)
                                            style="margin-top:.2rem">
                                        <span>
                                            <span style="font-weight:600;font-size:.88rem;display:block">
                                                {{ $moyen['libelle'] }}
                                            </span>
                                            <span style="font-size:.78rem;color:var(--color-text-muted)">
                                                {{ $moyen['description'] }}
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            {{-- Un seul moyen : envoyé en champ caché. Le serveur le
                                 revalide de toute façon — un formulaire trafiqué ne
                                 peut pas imposer une passerelle non proposable. --}}
                            @foreach ($moyens as $cle => $moyen)
                                <input type="hidden" name="gateway" value="{{ $cle }}">
                            @endforeach
                        @endif

                        <button type="submit" class="btn btn-primary btn-block">
                            <i data-lucide="credit-card" style="width:16px;height:16px"></i>
                            Payer {{ number_format($lien->amount_fcfa, 0, ',', ' ') }} {{ $lien->currency }}
                        </button>
                    </form>

                    <p style="font-size:.78rem;color:var(--color-text-muted);text-align:center;margin:1rem 0 0">
                        Vous serez redirigé vers la plateforme sécurisée du fournisseur pour
                        finaliser le règlement.
                    </p>
                @endif
            @else
                {{-- Lien hors ligne : aucun bouton de paiement. En afficher un serait
                     une promesse non tenue — le règlement a lieu par un autre canal. --}}
                <div class="banner banner-info" style="margin:0">
                    <i data-lucide="hand-coins"></i>
                    <div>
                        <strong>Règlement hors ligne</strong>
                        <div style="font-size:.85rem;margin-top:.25rem">
                            Ce montant se règle par un autre moyen (espèces, virement, mobile money
                            direct), convenu avec votre interlocuteur. Aucun paiement en ligne n'est
                            attendu sur cette page.
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>

    <p style="text-align:center;font-size:.75rem;color:var(--color-text-muted);margin-top:1.5rem">
        @if (! empty($moyens))
            Moyens de paiement : {{ implode(', ', array_column($moyens, 'libelle')) }} ·
        @endif
        <a href="/">FORMADOC</a>
    </p>
</div>

