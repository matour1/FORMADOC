{{--
    Graphique en barres, en CSS pur.

    Les barres sont des `<div>` dont la hauteur est un pourcentage. Aucune
    bibliothèque : le graphique reste lisible si le JavaScript échoue, et les
    valeurs sont dans le DOM (inspectables, copiables) plutôt que dessinées dans
    un canevas.

    `title` sur chaque groupe : c'est ce qui donne la valeur EXACTE du jour, y
    compris pour les jours dont l'étiquette est masquée faute de place.
--}}
<div class="chart-bars" style="height:{{ $hauteur }}px">
    @foreach ($labels as $i => $label)
        @php
            $masquer = ($i % $pasEtiquettes()) !== 0;
            $details = [];

            foreach ($series as $cle => $valeurs) {
                if (! is_array($valeurs) || ! array_key_exists($i, $valeurs)) {
                    continue;
                }

                $valeur = $valeurs[$i];
                $format = $formats[$cle] ?? 'entier';
                $details[] = match ($format) {
                    'credit' => number_format((float) $valeur, 0, ',', ' ').' crédit(s)',
                    default => number_format((float) $valeur, 0, ',', ' '),
                };
            }
        @endphp

        <div class="chart-bar-group" title="{{ $label }} — {{ implode(' · ', $details) }}">
            <div class="chart-bar-track">
                @foreach ($series as $cle => $valeurs)
                    @php
                        if (! is_array($valeurs) || ! array_key_exists($i, $valeurs)) {
                            continue;
                        }

                        $valeur = $valeurs[$i];
                        $couleur = $couleurs[$cle] ?? '';
                        $classes = trim('chart-bar '.$couleur.((float) $valeur <= 0 ? ' muted' : ''));
                    @endphp
                    <div class="{{ $classes }}" style="height:{{ $hauteurPour($valeur) }}%"></div>
                @endforeach
            </div>

            <div class="chart-bar-label {{ $masquer ? 'thin' : '' }}"
                 @if ($masquer) aria-hidden="true" @endif>{{ $label }}</div>
        </div>
    @endforeach
</div>

@if ($legende !== '')
    <p style="font-size:.75rem;color:var(--color-text-muted);margin:.75rem 0 0">{{ $legende }}</p>
@endif
