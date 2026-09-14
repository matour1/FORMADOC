<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\StructuralSchemaValidator;

/**
 * Validation de la sortie de chaque tool (garde-fou §9.9).
 *
 * **Le problème** — un modèle de langage renvoie du texte. Il peut produire un
 * `block_id` inexistant, un niveau de titre à 12, un type inventé (`paragraphe`
 * au lieu de `paragraph`), ou du JSON tronqué. Chaque cas produirait une
 * structure corrompue si on l'appliquait tel quel.
 *
 * **La réponse en deux temps** :
 *  1. **rejet** — une sortie qui ne respecte pas le contrat est refusée, avec une
 *     raison exploitable. Appliquer une correction invalide ferait plus de dégâts
 *     que de ne rien appliquer ;
 *  2. **retry** — le contrat de rejet est renvoyé au modèle, qui peut reformuler.
 *     C'est ce qui distingue un système robuste d'un système qui échoue.
 *
 * **Ce que ce composant ne fait PAS** — corriger à la place du modèle. Deviner
 * que « paragraphe » voulait dire « paragraph » est faisable, mais généraliser
 * cette tolérance masquerait des erreurs réelles. On accepte les variantes
 * explicitement listées (`TYPE_ALIASES`), on refuse le reste.
 */
final class ToolOutputValidator
{
    /**
     * Variantes de types acceptées, et leur équivalent canonique.
     *
     * Volontairement restreint aux confusions prévisibles entre le français et
     * l'anglais : le schéma est en anglais, mais une instruction utilisateur
     * l'est en français, et le modèle mélange parfois les deux.
     */
    private const TYPE_ALIASES = [
        'paragraphe' => 'paragraph',
        'titre' => 'heading',
        'tableau' => 'table',
        'legende' => 'caption',
        'légende' => 'caption',
        'renvoi' => 'cross_ref',
        'en_tete' => 'header',
        'en-tete' => 'header',
        'pied' => 'footer',
    ];

    /**
     * Types de blocs qu'un tool ne peut pas insérer.
     *
     * `header` et `footer` vivent dans les zones de page, pas dans le flux : les
     * insérer au milieu du corps produirait un document incohérent. `cross_ref`
     * est produit par la résolution des renvois (R4), jamais inséré à la main.
     */
    private const TYPES_INTERDITS = ['header', 'footer', 'cross_ref'];

    public function __construct(
        private readonly StructuralSchemaValidator $schemaValidator = new StructuralSchemaValidator,
    ) {}

    /**
     * Valide les arguments d'un tool avant exécution.
     *
     * @param  string  $tool  Nom du tool
     * @param  array<string, mixed>  $arguments  Arguments déduits du JSON du modèle
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateArguments(string $tool, array $arguments): array
    {
        $erreurs = [];

        if (! ToolWhitelist::allows($tool)) {
            $erreurs[] = "L'outil « {$tool} » n'est pas autorisé.";
        }

        $erreurs = [...$erreurs, ...$this->champsRequis($tool, $arguments)];

        if ($tool === 'insert_block') {
            $erreurs = [...$erreurs, ...$this->validationsInsertion($arguments)];
        }

        if ($tool === 'regenerate_section') {
            $erreurs = [...$erreurs, ...$this->validationsPlage($arguments)];
        }

        return ['valid' => $erreurs === [], 'errors' => $erreurs];
    }

    /**
     * Valide le document résultant d'un tool.
     *
     * C'est la vérification qui compte : même si les arguments semblaient
     * corrects, le résultat doit être un document structurel cohérent.
     *
     * @param  StructuralDocument  $document  Document produit par le tool
     * @param  string  $tool  Nom du tool, pour le message d'erreur
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateResult(StructuralDocument $document, string $tool): array
    {
        // Le validateur de schéma travaille sur le tableau sérialisé (§6) :
        // c'est le format d'échange, donc le seul dont la conformité compte.
        $erreurs = $this->schemaValidator->errors($document->toArray());

        // Un document vide n'est pas « invalide » au sens du schéma, mais aucune
        // édition ne doit aboutir à un document sans aucun bloc : c'est le signe
        // qu'une suppression a mal tourné.
        if ($document->count() === 0) {
            $erreurs[] = "L'outil « {$tool} » a produit un document vide.";
        }

        return ['valid' => $erreurs === [], 'errors' => $erreurs];
    }

    /**
     * Valide les arguments, et lève si nécessaire.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException
     */
    public function assertArguments(string $tool, array $arguments): void
    {
        $resultat = $this->validateArguments($tool, $arguments);

        if (! $resultat['valid']) {
            throw EditingException::sortieInvalide($tool, implode(' ; ', $resultat['errors']));
        }
    }

    /**
     * Valide le résultat, et lève si nécessaire.
     *
     * @throws EditingException
     */
    public function assertResult(StructuralDocument $document, string $tool): void
    {
        $resultat = $this->validateResult($document, $tool);

        if (! $resultat['valid']) {
            throw EditingException::sortieInvalide($tool, implode(' ; ', $resultat['errors']));
        }
    }

    /**
     * Normalise un type de bloc produit par le modèle.
     *
     * Retourne null si le type est inexploitable : à l'appelant de décider s'il
     * rejette (ce que `validateArguments` fait) ou propose un repli.
     */
    public function normalizeType(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $normalise = mb_strtolower(trim($type));

        // Alias français d'abord, puis les valeurs du schéma.
        $normalise = self::TYPE_ALIASES[$normalise] ?? $normalise;

        foreach (BlockType::cases() as $cas) {
            if ($cas->value === $normalise) {
                return $normalise;
            }
        }

        return null;
    }

    /**
     * Normalise un identifiant de bloc.
     *
     * Le modèle renvoie parfois « b_42 » au lieu de « b_0042 », ou entoure la
     * valeur de guillemets. On accepte ces variations : elles ne changent pas le
     * sens. On refuse en revanche un identifiant vide.
     */
    public function normalizeBlockId(mixed $blockId): ?string
    {
        if (! is_string($blockId)) {
            return null;
        }

        $nettoye = trim($blockId, " \t\n\r\0\x0B\"'`");

        return $nettoye === '' ? null : $nettoye;
    }

    /**
     * Normalise un niveau de titre.
     *
     * Ramené à l'intervalle 1–3 : c'est ce que le gabarit gère (voir
     * `HeadingFormatter::MAX_LEVEL`), et un niveau supérieur ne serait pas stylé.
     */
    public function normalizeHeadingLevel(mixed $level): ?int
    {
        if ($level === null || $level === '') {
            return null;
        }

        if (! is_numeric($level)) {
            return null;
        }

        return min(3, max(1, (int) $level));
    }

    /**
     * Champs obligatoires d'un tool.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function champsRequis(string $tool, array $arguments): array
    {
        $requis = match ($tool) {
            'rewrite_paragraph', 'delete_block' => ['block_id'],
            'insert_block' => ['position_block_id', 'type'],
            'modify_table' => ['block_id'],
            'regenerate_section' => ['start_block_id', 'end_block_id'],
            default => [],
        };

        $erreurs = [];

        foreach ($requis as $champ) {
            if (! isset($arguments[$champ]) || $arguments[$champ] === '') {
                $erreurs[] = "Le paramètre « {$champ} » est obligatoire.";
            }
        }

        return $erreurs;
    }

    /**
     * Validations propres à l'insertion.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function validationsInsertion(array $arguments): array
    {
        $erreurs = [];
        $type = $arguments['type'] ?? null;

        $normalise = $this->normalizeType($type);

        if ($normalise === null) {
            $autorises = implode(', ', ToolWhitelist::insertableTypes());
            $erreurs[] = 'Le type « '.(is_scalar($type) ? (string) $type : '?')
                ." » est inconnu. Types acceptés : {$autorises}.";
        } elseif (in_array($normalise, self::TYPES_INTERDITS, true)) {
            $erreurs[] = "Le type « {$normalise} » ne peut pas être inséré dans le corps du document.";
        } elseif (! in_array($normalise, ToolWhitelist::insertableTypes(), true)) {
            $erreurs[] = "Le type « {$normalise} » n'est pas insérable.";
        }

        return $erreurs;
    }

    /**
     * Validations propres à la régénération de section.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function validationsPlage(array $arguments): array
    {
        $debut = $arguments['start_block_id'] ?? null;
        $fin = $arguments['end_block_id'] ?? null;

        if ($debut === $fin) {
            return ['Le début et la fin de la plage désignent le même bloc.'];
        }

        return [];
    }
}
