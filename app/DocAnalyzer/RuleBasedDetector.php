<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use InvalidArgumentException;

/**
 * Détection déterministe des éléments structurels (titres, en-têtes,
 * pieds de page, tableaux, images) à partir des STYLES réels du document.
 *
 * Contrairement à l'IA, les règles sont prévisibles et ne dépendent pas du
 * contenu textuel : on se base sur les styles de paragraphe/font (HeadingN,
 * tailles, gras) et sur la position (parent header/footer, type tableau/image).
 *
 * Les règles proviennent de config/analyzer.yaml (chargé par DocAnalyzer).
 *
 * Format d'une règle (YAML) :
 *   - nom: titre_niveau_1
 *     categorie: titres
 *     quand:
 *       style_name: Heading1          # OU
 *       font_size: { >=: 16 }         # comparaison (>=, >, <=, <)
 *       gras: true
 *       parent: body                  # body|header|footer|*
 *       type: titre                   # type d'élément PhpWord normalisé
 *     niveau: 1                       # optionnel, pour les titres
 */
class RuleBasedDetector
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $rules;

    /**
     * @param  array<int, array<string, mixed>>  $rules  Règles issues du YAML
     *
     * @throws InvalidArgumentException Si les règles sont vides ou invalides
     */
    public function __construct(array $rules)
    {
        $this->rules = $this->validateRules($rules);
    }

    /**
     * Détecte les éléments structurels du document analysé.
     *
     * @param  array<string, mixed>  $parsedData  Sortie de DocumentParser::parse()
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function detect(array $parsedData): array
    {
        $result = AnalyzerResult::empty();

        // Aplatit tous les éléments avec leur position (body, headers, footers)
        $elements = $this->flatten($parsedData);

        foreach ($elements as $element) {
            foreach ($this->rules as $rule) {
                if ($this->matchesRule($element, $rule)) {
                    $result[$rule['categorie']][] = $this->buildItem($element, $rule);
                    // Un élément ne peut matcher qu'une seule règle : on s'arrête
                    // à la première règle qui correspond (priorité d'ordre du YAML).
                    break;
                }
            }
        }

        // Tri par position (section_index, puis element_index)
        foreach ($result as &$items) {
            usort($items, static function (array $a, array $b): int {
                $pa = $a['position'] ?? null;
                $pb = $b['position'] ?? null;

                return self::comparePositions($pa, $pb);
            });
        }
        unset($items);

        return $result;
    }

    /**
     * Valide et normalise les règles fournies.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private function validateRules(array $rules): array
    {
        if ($rules === []) {
            throw new InvalidArgumentException(
                'RuleBasedDetector : aucune règle fournie (config/analyzer.yaml introuvable ou vide ?)'
            );
        }

        foreach ($rules as $index => $rule) {
            if (! is_array($rule) || ! isset($rule['categorie'])) {
                throw new InvalidArgumentException(
                    "RuleBasedDetector : règle invalide à l'index {$index} (catégorie manquante)"
                );
            }

            $categorie = (string) $rule['categorie'];
            if (! in_array($categorie, AnalyzerResult::CATEGORIES, true)) {
                throw new InvalidArgumentException(
                    "RuleBasedDetector : catégorie inconnue \"{$categorie}\" (règle {$index})"
                );
            }

            if (! isset($rule['quand']) || ! is_array($rule['quand']) || $rule['quand'] === []) {
                throw new InvalidArgumentException(
                    "RuleBasedDetector : règle {$index} sans condition (\"quand\")"
                );
            }

            // Normalisation : clés communes
            $rule['categorie'] = $categorie;
            $rule['nom'] = (string) ($rule['nom'] ?? "regle_{$index}");
            $rule['niveau'] = isset($rule['niveau']) ? (int) $rule['niveau'] : null;
            $rules[$index] = $rule;
        }

        return $rules;
    }

    /**
     * Aplatit les éléments de toutes les sections (body/headers/footers)
     * en une liste unique, en conservant la position.
     *
     * @param  array<string, mixed>  $parsedData
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $parsedData): array
    {
        $elements = [];

        // Clé dans les sections => nom de parent normalisé (singulier)
        $containers = [
            'body' => 'body',
            'headers' => 'header',
            'footers' => 'footer',
        ];

        foreach (($parsedData['sections'] ?? []) as $sectionIndex => $section) {
            foreach ($containers as $container => $parentName) {
                foreach (($section[$container] ?? []) as $element) {
                    // Position normalisée (parent au singulier : header/footer)
                    $element['position'] = [
                        'section_index' => $sectionIndex,
                        'element_index' => $element['position']['element_index'] ?? 0,
                        'parent' => $parentName,
                    ];
                    $elements[] = $element;
                }
            }
        }

        return $elements;
    }

    /**
     * Vérifie si un élément correspond à une règle.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $rule
     */
    private function matchesRule(array $element, array $rule): bool
    {
        $style = $element['styles'] ?? [];
        $font = is_array($style['font'] ?? null) ? $style['font'] : [];
        $paragraph = is_array($style['paragraph'] ?? null) ? $style['paragraph'] : [];

        // Taille de police : la structure PhpWord la place dans font.basic.size,
        // mais on accepte aussi font.size directement (normalisation souple).
        $fontSize = $font['basic']['size'] ?? $font['size'] ?? null;
        $fontName = $font['basic']['name'] ?? $font['name'] ?? null;

        // Gras : font.style.bold ou font.bold (booléen)
        $bold = $font['style']['bold'] ?? $font['bold'] ?? false;

        // Style de paragraphe nommé (Heading1…) : paragraph.name ou paragraph.style_name
        $paragraphStyleName = $paragraph['name'] ?? $paragraph['style_name'] ?? '';

        foreach ($rule['quand'] as $condition => $expected) {
            switch ($condition) {
                case 'type':
                    if (($element['type'] ?? '') !== $expected) {
                        return false;
                    }
                    break;

                case 'parent':
                    $parent = $element['position']['parent'] ?? '';
                    if ($expected !== '*' && $parent !== $expected) {
                        return false;
                    }
                    break;

                case 'style_name':
                    // Matche le style de paragraphe (Heading1…) OU le nom de police
                    if ($paragraphStyleName !== $expected && $fontName !== $expected) {
                        return false;
                    }
                    break;

                case 'font_size':
                    if (! $this->compareValue((float) $fontSize, $expected)) {
                        return false;
                    }
                    break;

                case 'gras':
                    if ((bool) $bold !== (bool) $expected) {
                        return false;
                    }
                    break;

                case 'texte_contient':
                    if (! str_contains((string) ($element['text'] ?? ''), (string) $expected)) {
                        return false;
                    }
                    break;

                case 'texte_matche':
                    if (preg_match((string) $expected, (string) ($element['text'] ?? '')) !== 1) {
                        return false;
                    }
                    break;

                default:
                    // Condition inconnue : on considère l'élément non matché
                    // (config invalide) — plus sûr que de matcher par erreur.
                    return false;
            }
        }

        return true;
    }

    /**
     * Compare une valeur à une attente de comparaison.
     *
     * $expected peut être :
     *  - un scalaire          → égalité stricte
     *  - ['>=' => x]          → valeur >= x
     *  - ['>'  => x]          → valeur > x
     *  - ['<=' => x]          → valeur <= x
     *  - ['<'  => x]          → valeur < x
     *
     * @param  mixed  $value
     * @param  mixed  $expected
     */
    private function compareValue($value, $expected): bool
    {
        if (is_array($expected)) {
            foreach ($expected as $operator => $threshold) {
                $threshold = (float) $threshold;

                return match ($operator) {
                    '>=' => (float) $value >= $threshold,
                    '>' => (float) $value > $threshold,
                    '<=' => (float) $value <= $threshold,
                    '<' => (float) $value < $threshold,
                    default => false,
                };
            }
        }

        return $value == $expected;
    }

    /**
     * Construit l'item de résultat pour un élément matché.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function buildItem(array $element, array $rule): array
    {
        $item = [
            'texte' => (string) ($element['text'] ?? ''),
            'position' => $element['position'] ?? null,
            'styles' => $element['styles'] ?? [],
            'type' => $rule['categorie'],
        ];

        if ($rule['niveau'] !== null) {
            $item['niveau'] = $rule['niveau'];
        }

        // Métadonnées utiles conservées
        foreach (['depth', 'rows_count', 'image_name'] as $meta) {
            if (isset($element[$meta])) {
                $item[$meta] = $element[$meta];
            }
        }

        return $item;
    }

    /**
     * Compare deux positions (null = fin de liste).
     *
     * @param  mixed  $a
     * @param  mixed  $b
     */
    private static function comparePositions($a, $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        $sa = (int) ($a['section_index'] ?? 0);
        $sb = (int) ($b['section_index'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        $ea = (int) ($a['element_index'] ?? 0);
        $eb = (int) ($b['element_index'] ?? 0);

        return $ea <=> $eb;
    }
}
