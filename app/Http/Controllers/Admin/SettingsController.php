<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\UsageCostCalculator;
use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuration de l'exploitation, sans toucher au code.
 *
 * **Ce que cet écran rend possible.** Ajuster la marge, le coût d'infrastructure,
 * le taux de change ou le montant minimum d'achat demandait d'éditer un fichier
 * PHP, de commiter et de redéployer. C'étaient pourtant les décisions les plus
 * fréquentes de l'exploitation — et les plus urgentes quand le taux de change
 * décroche du réel, puisque chaque appel IA continue d'être facturé sur l'ancienne
 * base pendant toute la durée du cycle de livraison.
 *
 * **Pourquoi les bornes sont dans le catalogue et non dans la validation.** La
 * valeur minimale et maximale d'une marge ne sont pas des règles de saisie mais
 * des propriétés du réglage : `SettingsCatalog` les déclare à côté du libellé et
 * de la description. La validation les LIT. Dupliquées ici, elles finiraient par
 * divergent du catalogue et l'écran afficherait « entre 0 et 5 » tout en
 * acceptant 40.
 *
 * **Pourquoi l'effet est montré après enregistrement.** Changer la marge modifie
 * le prix payé par tous les utilisateurs suivants. Le message de confirmation
 * annonce donc l'ANCIENNE et la NOUVELLE valeur, et non un « enregistré » qui
 * laisserait l'exploitant incertain de ce qui vient de changer — un taux de change
 * saisi de travers se paie sur chaque appel.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly UsageCostCalculator $calculator,
    ) {}

    /**
     * Écran de configuration, groupé par thème.
     */
    public function index(): View
    {
        $definitions = SettingsCatalog::all();

        // Valeurs COURANTES : celles enregistrées si elles existent, sinon le
        // défaut du catalogue. `config()` ne convient pas ici — un réglage
        // enregistré y est déjà appliqué, mais on ne peut plus distinguer une
        // valeur saisie d'un défaut, et l'écran doit pouvoir le dire.
        //
        // L'écart est calculé sur la VALEUR, pas sur la présence d'une ligne. Le
        // formulaire soumet tous les champs à la fois : dès le premier
        // enregistrement, toutes les lignes existent. Si le badge s'appuyait sur
        // l'existence d'une ligne, il afficherait « modifié » sur les douze
        // réglages alors que onze sont restés au défaut — le badge perdrait
        // exactement l'information qu'on attend de lui : « qu'est-ce qui a
        // réellement été changé par rapport à l'origine ? »
        $valeurs = [];

        foreach ($definitions as $nom => $definition) {
            $enregistre = $this->settings->get($definition['key']);

            $valeurs[$nom] = [
                'valeur' => $enregistre ?? $definition['default'],
                'modifie' => $enregistre !== null
                    && ! $this->valeursEquivalentes($enregistre, $definition['default']),
            ];
        }

        return view('admin.settings', [
            'groupes' => SettingsCatalog::GROUPS,
            'definitions' => $definitions,
            'valeurs' => $valeurs,
            // Effet des valeurs COURANTES : c'est ce qui donne un sens au
            // coefficient, qui ne se saisit pas mais résulte des deux autres.
            'coefficient' => $this->calculator->profitabilityCoefficient(),
        ]);
    }

    /**
     * Enregistre les réglages soumis.
     */
    public function update(Request $request): RedirectResponse
    {
        $definitions = SettingsCatalog::all();

        // Le nom du champ soumis est le nom du réglage dans le catalogue
        // (`cost_margin`), pas son nom de configuration : un formulaire qui
        // accepterait `openrouter.cost_margin` comme nom de champ permettrait de
        // viser n'importe quelle clé de configuration, y compris celles qu'on ne
        // veut pas exposer.
        $regles = [];
        $libelles = [];

        foreach ($definitions as $nom => $definition) {
            $libelles[$nom] = $definition['label'];
            $regles[$nom] = $this->reglesPour($definition);
        }

        $donnees = $request->validate($regles, $this->messages(), $libelles);

        // Valeurs AVANT modification : le message de confirmation les annonce
        // pour que l'exploitant voie ce qui a changé. Un booléen non coché
        // n'étant pas transmis, `$donnees` ne le contient pas — d'où la lecture
        // des valeurs courantes plutôt que des données soumises.
        $avant = [];

        foreach ($definitions as $nom => $definition) {
            $avant[$nom] = $this->settings->get($definition['key']) ?? $definition['default'];
        }

        $aEnregistrer = [];

        foreach ($definitions as $nom => $definition) {
            // --- Cas du booléen : la difficulté est réelle ----------------------
            //
            // Une case à cocher non soumise vaut faux, et c'est le SEUL cas où
            // l'absence de donnée est une information plutôt qu'une omission. Mais
            // cette même règle rend le comportement destructeur : une requête qui
            // n'envoie QUE `cost_margin` (soumission partielle, appel direct à
            // l'API, script de maintenance) décocherait silencieusement
            // `billing.auto_renew` et `billing.prorata`. Le renouvellement
            // automatique s'arrêterait pour tous les abonnements — sans erreur,
            // sans trace, et sans que personne n'ait touché à ces cases.
            //
            // On n'enregistre donc la valeur d'un booléen que si le formulaire a
            // RÉELLEMENT soumis ce champ. Le formulaire de l'écran déclare pour
            // cela un `<input type="hidden">` à `0` avant chaque case : quand la
            // case est décochée, le champ caché transmet quand même `0`.
            if ($definition['type'] === 'bool' && ! $request->exists($nom)) {
                continue;
            }

            $valeur = $definition['type'] === 'bool'
                ? $request->boolean($nom)
                : ($donnees[$nom] ?? null);

            if ($valeur === null) {
                continue;
            }

            $aEnregistrer[$definition['key']] = [
                'value' => $valeur,
                'type' => $definition['type'],
            ];
        }

        $this->settings->setMany($aEnregistrer, $request->user()?->id);

        // --- Effet du changement, calculé sur l'état RÉSULTANT --------------
        //
        // On ne lit pas le coefficient depuis `UsageCostCalculator` : il lit
        // `config('openrouter.cost_margin')`, qui n'est surchargé qu'au DÉMARRAGE
        // de la requête. Dans la requête courante, il porte encore l'ancienne
        // valeur — le message aurait annoncé « 1,84 → 1,84 », c'est-à-dire qu'il
        // aurait affirmé qu'aucun changement n'avait eu lieu alors que la base
        // venait d'être modifiée.
        //
        // On ne lit pas non plus `$aEnregistrer` seul : un formulaire peut ne
        // soumettre qu'une partie des réglages (un groupe à la fois, ou un seul
        // champ), et les clés absentes feraient échouer le calcul sur un
        // « Undefined array key » — ce que ce code faisait, et que le test a
        // révélé. La valeur effective est donc : la valeur soumise si elle est
        // présente, sinon celle déjà en vigueur.
        $effectif = static function (string $configKey, string $nom, array $aEnregistrer, array $avant): float {
            return (float) ($aEnregistrer[$configKey]['value'] ?? $avant[$nom] ?? 0);
        };

        $apres = (1 + $effectif('openrouter.cost_infrastructure', 'cost_infrastructure', $aEnregistrer, $avant))
            * (1 + $effectif('openrouter.cost_margin', 'cost_margin', $aEnregistrer, $avant));

        $avantCoefficient = (1 + (float) $avant['cost_infrastructure']) * (1 + (float) $avant['cost_margin']);

        return redirect()
            ->route('admin.settings')
            ->with('success', sprintf(
                'Configuration enregistrée. Coefficient de rentabilité : %.2f → %.2f.',
                $avantCoefficient,
                $apres,
            ));
    }

    /**
     * Deux valeurs de réglage sont-elles équivalentes ?
     *
     * La comparaison est LOOSE (`==`) volontairement, et c'est le point délicat.
     * `SettingsRepository::get()` renvoie un `float` (0.6) quand le catalogue
     * déclare un `float` (0.6) — mais un `int` (500) face à un `int` (500) reste
     * un `int`, et un booléen devient `true`/`false`. Une comparaison stricte
     * ferait échouer le rapprochement dès qu'un type diffère légèrement (un `int`
     * 1 face à un `float` 1.0), et le badge « modifié » s'allumerait sur un
     * réglage jamais touché.
     */
    private function valeursEquivalentes(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return $a == $b;
    }

    /**
     * Messages de validation, en français.
     *
     * Les messages par défaut de Laravel sont en anglais. Sur un écran
     * d'exploitation, un « The Marge appliquée field must not be greater than 5 »
     * n'indique pas la BORNE en vigueur, que l'exploitant doit justement corriger
     * à la main : le message affiche la règle en anglais et la valeur refusée
     * nulle part.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'max' => 'La valeur de :attribute dépasse le maximum autorisé de :max.',
            'min' => 'La valeur de :attribute est inférieure au minimum autorisé de :min.',
            'integer' => 'La valeur de :attribute doit être un nombre entier.',
            'numeric' => 'La valeur de :attribute doit être un nombre.',
            'size' => 'La valeur de :attribute doit contenir exactement :size caractères.',
            'alpha' => 'La valeur de :attribute ne doit contenir que des lettres.',
            'boolean' => 'La valeur de :attribute doit être vrai ou faux.',
        ];
    }

    /**
     * Règles de validation d'un réglage, dérivées du catalogue.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function reglesPour(array $definition): array
    {
        $regles = ['nullable'];

        $regles[] = match ($definition['type']) {
            'int' => 'integer',
            'float' => 'numeric',
            'bool' => 'boolean',
            'json' => 'json',
            default => 'string',
        };

        if (isset($definition['min'])) {
            $regles[] = 'min:'.$definition['min'];
        }

        if (isset($definition['max'])) {
            $regles[] = 'max:'.$definition['max'];
        }

        // La devise est un code ISO : la laisser libre produirait une valeur que
        // KPay rejetterait à l'initiation du paiement, donc une erreur au moment
        // le plus coûteux — quand le client est devant la page de règlement.
        if ($definition['type'] === 'string' && str_ends_with($definition['key'], '.currency')) {
            $regles[] = 'size:3';
            $regles[] = 'alpha';
        }

        return $regles;
    }
}
