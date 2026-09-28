@props([
    // Préfixe des `id` : deux sélecteurs coexistent sur le site (upload et
    // réanalyse), et des `id` dupliqués casseraient le `label for`.
    'prefix' => 'm',
    // Mode actuellement sélectionné (clé de DocumentModePricing).
    'selected' => 'regex',
    // Modes à afficher, construits par le contrôleur (chaque entrée porte son
    // prix, calculé depuis la taille réelle ou de référence du document).
    'modes' => [],
    // Phrase précisant sur quoi le prix est calculé, quand la taille exacte
    // n'est pas encore connue (page d'envoi).
    'noteTarif' => null,
])

{{--
    Sélecteur du mode d'analyse d'un document, avec le tarif de chaque mode.

    **UN SEUL contrôle, et c'est une correction.** Il existait auparavant deux
    réglages pour la même décision : un groupe de boutons radio
    (`title_method = regex | ia`) et une case à cocher indépendante
    (« confirmer les passages incertains »). Or trois modes coexistent :

      | Mode réel             | title_method | use_ai |
      |-----------------------|--------------|--------|
      | Détection automatique | regex        | non    |
      | Assistance IA         | regex        | OUI    |
      | Pleine précision      | ia           | non    |

    Les deux contrôles pouvaient donc décrire des combinaisons qui ne
    correspondent à AUCUN mode (`ia` + `use_ai` coché : demander la lecture
    complète ET l'assistance sur les ambiguïtés, deux fois la même dépense), et
    rien n'indiquait quel était le mode effectivement appliqué.

    Un groupe unique nommé `mode` rend ces combinaisons impossibles à exprimer :
    le contrôleur dérive lui-même `title_method` et `use_ai` du mode choisi.

    **Le prix est affiché pour chaque mode, avant le choix.** L'utilisateur doit
    pouvoir comparer ce qu'il paie avant de décider — et non le découvrir après.
    Les montants viennent de `DocumentModePricing`, donc de la grille tarifaire
    réelle et du plan de l'utilisateur : aucun prix n'est écrit en dur ici.
--}}

<div class="mode-grid" role="radiogroup" aria-label="Mode d'analyse de la structure">
    @foreach ($modes as $mode)
        @php
            $id = $prefix.'-'.$mode['key'];
        @endphp
        <label class="radio-card mode-card" for="{{ $id }}">
            <input type="radio" name="mode" id="{{ $id }}" value="{{ $mode['key'] }}"
                   @checked($selected === $mode['key'])
                   data-mode="{{ $mode['key'] }}">

            <span class="rc-icon">
                <i data-lucide="{{ $mode['icon'] }}" style="width:17px;height:17px"></i>
            </span>

            <span style="min-width:0">
                <strong>{{ $mode['label'] }}</strong>

                {{-- Le tarif AVANT la description : c'est la question que se pose
                     l'utilisateur en premier. L'enterrer en fin de bloc
                     reviendrait à la cacher. --}}
                <span class="mode-price {{ $mode['gratuit'] ? 'is-free' : '' }}">
                    @if ($mode['gratuit'])
                        <i data-lucide="gift" style="width:12px;height:12px"></i>
                        Gratuit — aucun crédit
                    @else
                        <i data-lucide="coins" style="width:12px;height:12px"></i>
                        <span data-price-for="{{ $mode['key'] }}">{{ $mode['credits'] }}</span>
                        crédit(s)
                    @endif
                </span>

                <small>{{ $mode['description'] }}</small>

                @if (! $mode['gratuit'] && ! empty($mode['detail']))
                    {{-- Le détail du calcul, pour que le montant soit vérifiable
                         et non un nombre tombé du ciel. --}}
                    <small class="mode-detail">{{ $mode['detail'] }}</small>
                @endif
            </span>
        </label>
    @endforeach
</div>

@if ($noteTarif)
    <p class="mode-note">
        <i data-lucide="info" style="width:12px;height:12px;display:inline-block;vertical-align:-2px"></i>
        {{ $noteTarif }}
    </p>
@endif
