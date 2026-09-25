<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Graphique en barres, sans bibliothèque JavaScript.
 *
 * **Pourquoi pas Chart.js ou équivalent.** Trois raisons, dans l'ordre d'importance :
 * le graphique doit rester lisible quand le JavaScript échoue (une courbe absente
 * sur un tableau d'exploitation est pire qu'une courbe sommaire) ; les valeurs
 * doivent rester traçables jusqu'à la donnée, ce qu'un canevas ne permet pas —
 * on ne peut ni les inspecter ni les copier ; et la page n'a pas à charger une
 * dépendance externe supplémentaire pour dessiner des rectangles.
 *
 * **Le problème réel que ce composant résout.** Avec 30 jours dont 28 sans
 * activité, les barres nulles doivent être VISIBLES comme des zéros, pas absentes.
 * Un graphique dont les jours vides sont invisibles ne dit pas « rien ne s'est
 * passé », il dit « les données manquent ». Un plancher de hauteur et un gris franc
 * les rendent perceptibles.
 *
 * **Pourquoi les étiquettes s'espacent.** Sur 30 points, afficher les 30 dates les
 * fait se chevaucher : elles occupent l'espace sans être lisibles. Une sur trois
 * suffit, et le titre au survol de chaque barre donne la valeur exacte du jour.
 */
class ChartBars extends Component
{
    /**
     * @param  array<int, string>  $labels  étiquettes, une par point
     * @param  array<string, array<int, float|int>|mixed>  $series  clé → valeurs
     * @param  array<string, string>  $couleurs  clé de série → '' (primaire) ou 'accent'
     * @param  array<string, string>  $formats  clé de série → formatage ('entier', 'credit')
     */
    public function __construct(
        public readonly array $labels,
        public readonly array $series,
        public readonly array $couleurs = [],
        public readonly array $formats = [],
        public readonly int $hauteur = 180,
        public readonly string $legende = '',
    ) {}

    /**
     * Pas d'affichage des étiquettes.
     *
     * Sur 30 points on en affiche une sur trois ; jusqu'à 12, toutes restent
     * lisibles. Le seuil est exprimé en nombre de points, pas en pourcentage : ce
     * qui compte est la largeur disponible par étiquette.
     */
    public function pasEtiquettes(): int
    {
        $points = count($this->labels);

        return match (true) {
            $points <= 12 => 1,
            $points <= 20 => 2,
            default => 3,
        };
    }

    /**
     * Maximum toutes séries confondues, utilisé comme échelle.
     *
     * Jamais zéro : une division par zéro produirait une hauteur `INF`, et la barre
     * disparaîtrait sans erreur visible. La série vide affiche donc des barres à
     * leur plancher, ce qui est le comportement voulu.
     */
    public function max(): float
    {
        $max = 0.0;

        foreach ($this->series as $valeurs) {
            if (! is_array($valeurs)) {
                continue;
            }

            foreach ($valeurs as $valeur) {
                $max = max($max, (float) $valeur);
            }
        }

        return $max > 0 ? $max : 1.0;
    }

    /**
     * Hauteur d'une barre, en pourcentage de l'échelle.
     *
     * Nommée `hauteurPour` et non `hauteur` : la propriété `$hauteur` (la hauteur
     * du bloc, en pixels) porterait sinon le même nom, et Blade résout `$hauteur`
     * vers la MÉTHODE — donc vers une closure, que `htmlspecialchars` refuse.
     * L'erreur (« Argument #1 must be of type string, Closure given ») ne dit pas
     * d'où vient le conflit.
     *
     * Le plancher de 3 % est ce qui rend un zéro visible. Sans lui, la barre
     * mesurerait 0 px : indistinguable d'un point absent de la série.
     */
    public function hauteurPour(float|int $valeur): float
    {
        $valeur = (float) $valeur;

        if ($valeur <= 0) {
            return 3.0;
        }

        return max(3.0, round($valeur / $this->max() * 100, 2));
    }

    /**
     * Total d'une série, pour la légende.
     */
    public function total(string $cle): float
    {
        $valeurs = $this->series[$cle] ?? [];

        return is_array($valeurs) ? (float) array_sum($valeurs) : 0.0;
    }

    public function render(): View
    {
        return view('components.chart-bars');
    }
}
