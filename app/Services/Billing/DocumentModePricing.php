<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\OpenRouter\ModelRouter;

/**
 * Modes de traitement d'un document et leur tarification.
 *
 * **Le défaut que ce service rend impossible.** Le prix d'un traitement était
 * dispersé : le mode gratuit consommait un quota en nombre, le mode IA complet
 * débitait des crédits estimés à partir de 2 000 tokens d'entrée, et
 * l'assistance IA n'était facturée NULLE PART — deux services (`DeepSeekAnalyzer`
 * et `AiCorrectionService`) appelaient l'API DeepSeek en direct, hors du
 * registre d'usage. L'utilisateur voyait donc trois options dont une seule
 * affichait un prix, et la plus coûteuse (le document ENTIER envoyé au modèle)
 * n'en affichait aucun.
 *
 * Ce service fait trois choses, et rien d'autre :
 *   1. il DÉFINIT les modes et leur nature (gratuit / payant) ;
 *   2. il ESTIME le coût d'un mode pour un document donné, à partir de la
 *      taille réelle de son texte ;
 *   3. il DÉBITE ce coût, avec un ajustement au coût réel après l'appel.
 *
 * **Pourquoi passer par `UsageCostCalculator` et non par une constante.** Le
 * prix d'un appel IA dépend du modèle choisi par `ModelRouter` pour le plan de
 * l'utilisateur, puis du nombre de tokens réellement échangés. Écrire des prix
 * en dur dans les vues les ferait diverger du coût réel dès qu'un modèle
 * change. Les vues affichent donc une ESTIMATION calculée par ce service, et
 * la facturation s'aligne sur le coût réel mesuré.
 */
final class DocumentModePricing
{
    /**
     * Le mode « analyse déterministe » : règles + motifs, aucun appel réseau.
     */
    public const MODE_REGEX = 'regex';

    /**
     * Le mode « assistance IA » : le déterministe est complété sur les seuls
     * éléments ambigus (liste courte, coût contenu).
     */
    public const MODE_ASSISTE = 'assiste';

    /**
     * Le mode « pleine précision » : le document ENTIER est envoyé au modèle,
     * sans présélection. Le plus coûteux, et de loin.
     *
     * **Ce mode enchaîne DEUX opérations, et son prix les couvre toutes les
     * deux :** l'analyse complète de la structure, PUIS la mise en forme
     * complète du document (`LongFormattingJob`, tâche `document_full_format`,
     * facturée séparément). Afficher seulement la première donnerait un prix
     * inférieur à la dépense réelle — précisément le défaut que ce travail
     * corrige.
     */
    public const MODE_PRECISION = 'precision';

    /**
     * Type de tâche du routeur pour le mode « pleine précision ».
     *
     * Le document entier est envoyé pour être STRUCTURÉ, ce qui correspond à
     * `document_analysis`. On n'utilise PAS `document_full_format` : ce type
     * désigne l'application d'un gabarit de mise en forme (`LongFormattingJob`),
     * une autre opération qui a son propre débit. Utiliser le même type pour
     * deux opérations facturées différemment rendrait le prix illisible.
     */
    private const TASK_PRECISION = 'document_analysis';

    /**
     * Type de tâche du routeur pour le mode « assistance IA ».
     *
     * L'assistance envoie une LISTE D'ÉLÉMENTS à classer, pas un document à
     * structurer : `chat_text` correspond à ce profil (raisonnement court sur
     * une entrée réduite). C'est aussi le type utilisé par le chat pour ses
     * appels textuels.
     *
     * **Ne jamais mettre ici un type absent de `config('openrouter.tasks')`** :
     * `ModelRouter::select()` lève une exception sur un type inconnu, et
     * l'erreur surviendrait au moment d'AFFICHER un prix — donc sur le chemin
     * de l'utilisateur, pas dans un test.
     */
    private const TASK_ASSISTE = 'chat_text';

    /**
     * Prix de l'API DeepSeek directe (USD par million de tokens).
     *
     * **Pourquoi un prix propre à ce mode.** Les deux modes payants n'appellent
     * pas la même API :
     *   - la pleine précision passe par `OpenRouterService`, dont le prix est
     *     dans `config('openrouter.pricing')` ;
     *   - l'assistance IA appelle l'API DeepSeek EN DIRECT
     *     (`AiCorrectionService`), avec `config('deepseek.model')`.
     *
     * Utiliser la grille OpenRouter pour l'assistance donnerait un prix pour un
     * modèle qui n'est pas celui appelé — précisément le genre d'écart qui rend
     * une facturation contestable. Ce prix est la même grille que
     * `deepseek/deepseek-chat` chez OpenRouter, qui route vers ce même modèle.
     * À réviser si le modèle direct change.
     */
    private const PRICING_DEEPSEEK_DIRECT = ['input' => 0.2574, 'output' => 1.029];

    public function __construct(
        private readonly UsageCostCalculator $calculator,
    ) {}

    /**
     * Les modes disponibles, avec leur nature et l'explication à afficher.
     *
     * L'ordre est celui de l'exposition croissante au coût : c'est aussi celui
     * dans lequel ils sont présentés à l'utilisateur, pour que le prix se lise
     * comme une progression et non comme une surprise.
     *
     * @return array<int, array{key: string, label: string, description: string, gratuit: bool, task_type: null|string}>
     */
    public function modes(): array
    {
        return [
            [
                'key' => self::MODE_REGEX,
                'label' => 'Détection automatique',
                'description' => 'Lecture des styles et des numérotations du document. '
                    .'Aucun envoi à l\'IA, résultat immédiat.',
                'gratuit' => true,
                'task_type' => null,
            ],
            [
                'key' => self::MODE_ASSISTE,
                'label' => 'Assistance IA',
                'description' => 'Détection automatique, puis vérification par l\'IA '
                    .'des seuls éléments ambigus. Coût proportionnel aux ambiguïtés, '
                    .'pas à la taille du document.',
                'gratuit' => false,
                'task_type' => self::TASK_ASSISTE,
            ],
            [
                'key' => self::MODE_PRECISION,
                'label' => 'Pleine précision',
                'description' => 'Le document ENTIER est envoyé à l\'IA, sans '
                    .'présélection. Le plus fiable sur les documents mal structurés, '
                    .'et le plus coûteux.',
                'gratuit' => false,
                'task_type' => self::TASK_PRECISION,
            ],
        ];
    }

    /**
     * Un mode est-il payant en crédits ?
     */
    public function isPaid(string $mode): bool
    {
        foreach ($this->modes() as $m) {
            if ($m['key'] === $mode) {
                return ! $m['gratuit'];
            }
        }

        return false;
    }

    /**
     * Libellé lisible d'un mode, ou la clé brute si elle est inconnue.
     *
     * Une clé inconnue est renvoyée telle quelle plutôt que remplacée par un
     * libellé générique : si un mode est renommé, on veut le voir dans
     * l'interface, pas le confondre avec un autre.
     */
    public function label(string $mode): string
    {
        foreach ($this->modes() as $m) {
            if ($m['key'] === $mode) {
                return $m['label'];
            }
        }

        return $mode;
    }

    /**
     * Estime le coût d'un mode pour un document dont on connaît la taille.
     *
     * @param  string  $mode  Une des constantes MODE_*
     * @param  int  $documentChars  Nombre de caractères du texte du document
     * @param  string  $plan  Plan de l'utilisateur (détermine le modèle choisi)
     * @return array{gratuit: bool, credits: int, usd: float, model: null|string, input_tokens: int, detail: string}
     */
    public function estimate(string $mode, int $documentChars, string $plan = 'default'): array
    {
        if (! $this->isPaid($mode)) {
            return [
                'gratuit' => true,
                'credits' => 0,
                'usd' => 0.0,
                'model' => null,
                'input_tokens' => 0,
                'detail' => 'Aucun appel IA : aucun crédit débité.',
            ];
        }

        $tokens = $this->estimateInputTokens($mode, $documentChars);
        $outputTokens = $this->estimateOutputTokens($mode, $documentChars);
        $model = $this->modelFor($mode, $plan);

        // Le prix dépend de l'API réellement appelée, pas du mode en soi :
        // l'assistance IA appelle DeepSeek en direct, la pleine précision passe
        // par OpenRouter. Voir PRICING_DEEPSEEK_DIRECT.
        $usd = $this->usdFor($mode, $model, $tokens, $outputTokens);

        // Pleine précision : le coût de la mise en forme complète s'AJOUTE à
        // celui de l'analyse. Les deux opérations ont lieu, les deux sont
        // facturées, et n'afficher que la première tromperait l'utilisateur sur
        // la dépense réelle.
        //
        // On passe la taille du TEXTE pour les deux paramètres : l'affichage ne
        // connaît que le nombre de caractères, pas la taille du fichier. La
        // première version multipliait par 4 pour « simuler » un fichier — or la
        // fonction DIVISE ce paramètre par 4 pour obtenir des tokens, donc
        // `chars * 4 / 4` redonnait le nombre de caractères, interprété comme des
        // tokens : trois fois trop. Le prix affiché passait de 21 à 36 crédits
        // sans que rien d'autre ne change. Une valeur inventée pour « faire
        // réaliste » produisait exactement une surestimation silencieuse.
        $creditsMiseEnForme = $mode === self::MODE_PRECISION
            ? self::estimationMiseEnForme($documentChars, $documentChars, $plan)
            : 0;

        return [
            'gratuit' => false,
            'credits' => max(1, $this->calculator->usdToCredits($usd)) + $creditsMiseEnForme,
            'usd' => round($usd, 6),
            'model' => $model,
            'input_tokens' => $tokens,
            'detail' => $this->detailFor($mode, $documentChars, $tokens, $creditsMiseEnForme),
        ];
    }

    /**
     * Coût estimé de la mise en forme complète (`LongFormattingJob`).
     *
     * **Publique et STATIQUE : elle est PARTAGÉE avec le déclenchement du job.**
     * Le contrôleur s'en sert pour créer le job, et `estimate()` pour afficher le
     * prix. Deux calculs séparés produiraient deux montants pour la même
     * opération — celui montré et celui débité. Un utilisateur qui voit 6 crédits
     * puis en paie 11 a raison de le reprocher, même si chaque calcul est juste
     * isolément.
     *
     * **Pourquoi l'estimation est PRUDENTE (elle surestime).** Le job calcule son
     * coût réel sur les tokens échangés, puis ajuste à la baisse. L'estimation
     * sert à vérifier le solde et à annoncer un prix : sous-estimer ferait échouer
     * le débit APRÈS une analyse déjà payée, et l'utilisateur aurait payé sans
     * recevoir son document. On préfère annoncer un peu plus et rendre la
     * différence.
     *
     * **Deux tailles en entrée, et on retient la plus GRANDE.** Le job reçoit la
     * taille du FICHIER (c'est ce dont il dispose à sa création) ; l'affichage
     * dispose en plus de la taille du TEXTE. Surestimer reste sûr, sous-estimer
     * non.
     *
     * @param  int  $tailleFichier  Octets du fichier envoyé
     * @param  int  $tailleTexte  Caractères du texte extrait
     */
    public static function estimationMiseEnForme(int $tailleFichier, int $tailleTexte, string $plan = 'default'): int
    {
        // Hypothèse du job : environ 4 caractères par octet, plancher 500 tokens.
        $parFichier = max(500, (int) ceil($tailleFichier / 4));

        // Hypothèse de l'affichage : 3 caractères par token.
        $parTexte = max(500, (int) ceil($tailleTexte / 3));

        $estimation = app(UsageCostCalculator::class)->estimateCredits(
            app(ModelRouter::class)->select('document_full_format', $plan)['model'],
            max($parFichier, $parTexte),
            1500,
        );

        return max(1, $estimation['credits']);
    }

    /**
     * Coût en USD pour un mode, selon l'API réellement appelée.
     */
    private function usdFor(string $mode, string $model, int $inputTokens, int $outputTokens): float
    {
        if ($mode === self::MODE_ASSISTE) {
            return ($inputTokens / 1_000_000) * self::PRICING_DEEPSEEK_DIRECT['input']
                 + ($outputTokens / 1_000_000) * self::PRICING_DEEPSEEK_DIRECT['output'];
        }

        // Pleine précision : grille OpenRouter, par modèle routé.
        // On passe par le calculateur, dont la lecture par TABLEAU évite le
        // piège de la notation pointée (les noms de modèles contiennent des
        // points : `anthropic/claude-3.5-sonnet`).
        $pricing = $this->calculator->pricingFor($model);

        if ($pricing === null || ! isset($pricing['input'], $pricing['output'])) {
            // Prix inconnu : on ne l'invente pas. L'estimation affichera 0 crédit,
            // ce qui est visible ; le débit réel utilisera le coût mesuré.
            return 0.0;
        }

        return ($inputTokens / 1_000_000) * (float) $pricing['input']
             + ($outputTokens / 1_000_000) * (float) $pricing['output'];
    }

    /**
     * Modèle qui sera réellement utilisé, pour le plan de l'utilisateur.
     *
     * Exposé parce que l'interface doit pouvoir dire QUEL modèle est facturé :
     * un plan supérieur route vers un modèle plus cher, et l'utilisateur a le
     * droit de le savoir avant de valider.
     */
    public function modelFor(string $mode, string $plan = 'default'): string
    {
        $taskType = $this->taskTypeFor($mode);

        return (string) app(ModelRouter::class)
            ->select($taskType, $plan)['model'];
    }

    /**
     * Type de tâche envoyé au routeur de modèles.
     */
    private function taskTypeFor(string $mode): string
    {
        foreach ($this->modes() as $m) {
            if ($m['key'] === $mode) {
                return (string) ($m['task_type'] ?? self::TASK_PRECISION);
            }
        }

        return self::TASK_PRECISION;
    }

    /**
     * Estimation du nombre de tokens d'entrée.
     *
     * **Une approximation explicite, pas une mesure inventée.** Le nombre exact
     * de tokens n'est connu qu'après l'appel, où l'API le renvoie. Avant l'appel
     * on ne peut qu'estimer, et l'usage courant est de retenir environ
     * 3 caractères par token pour du texte français (l'anglais en demande ~4).
     *
     * On retient 3 — la valeur PRUDENTE : surestimer légèrement vaut mieux que
     * sous-estimer, puisque l'estimation sert à vérifier le solde et à afficher
     * un prix. C'est aussi la valeur déjà retenue ailleurs dans le projet
     * (`ChatController`), donc l'application reste cohérente avec elle-même.
     *
     * La différence entre les modes n'est pas le nombre de caractères mais la
     * PROPORTION envoyée : le mode assisté n'envoie qu'une liste d'éléments
     * ambigus (majorée ici à 20 % du document), le mode pleine précision envoie
     * tout.
     */
    private function estimateInputTokens(string $mode, int $documentChars): int
    {
        $charsEnvoyes = match ($mode) {
            // Ambiguïtés : borne haute mesurée sur les documents du corpus, où
            // la part d'éléments ambigus reste sous 20 % du texte.
            self::MODE_ASSISTE => (int) min($documentChars * 0.20, $documentChars),
            // Pleine précision : le document ENTIER.
            default => $documentChars,
        };

        return (int) max(1, ceil($charsEnvoyes / 3));
    }

    /**
     * Estimation du nombre de tokens de sortie.
     *
     * La sortie ne recopie jamais le document :
     *   - pleine précision : le modèle renvoie des POSITIONS et des niveaux,
     *     pas le texte (format compact — voir `DeepSeekAnalyzer`) ;
     *   - assistance : le modèle renvoie des corrections ciblées.
     *
     * On retient donc une fraction de l'entrée, avec un plancher et un plafond
     * pour que les très petits et très gros documents ne donnent pas des
     * estimations absurdes.
     */
    private function estimateOutputTokens(string $mode, int $documentChars): int
    {
        $fraction = $mode === self::MODE_PRECISION ? 0.08 : 0.12;

        return (int) min(
            16384,
            max(200, (int) ceil($documentChars / 3 * $fraction)),
        );
    }

    /**
     * Phrase expliquant le calcul, affichée à l'utilisateur.
     */
    private function detailFor(string $mode, int $documentChars, int $inputTokens, int $creditsMiseEnForme = 0): string
    {
        if ($mode === self::MODE_PRECISION) {
            $base = 'Document entier ('.number_format($documentChars, 0, ',', ' ')
                .' caractères, soit environ '.number_format($inputTokens, 0, ',', ' ').' tokens)';

            // On dit explicitement que le prix couvre DEUX opérations : sans
            // cela, l'utilisateur ne comprendrait pas pourquoi la pleine
            // précision coûte plus que le double de l'assistance.
            if ($creditsMiseEnForme > 0) {
                return $base.', plus la mise en forme complète (~'
                    .number_format($creditsMiseEnForme, 0, ',', ' ').' crédits).';
            }

            return $base.'.';
        }

        return 'Éléments ambigus seulement (estimation haute : 20 % du document).';
    }
}
