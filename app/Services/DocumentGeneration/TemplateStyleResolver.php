<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

/**
 * Résolution des paramètres d'un gabarit (table `templates`) vers les
 * styles PhpWord (font, paragraph, section, table).
 *
 * Un gabarit est un tableau JSON (colonne `templates.params`) :
 *   {
 *     "police": "Times New Roman",
 *     "tailles": { "titre1": 16, "titre2": 14, "titre3": 12, "corps": 12 },
 *     "couleurs": { "titre1": "1F3864", "titre2": "1F3864", "titre3": "1F3864", "corps": "000000" },
 *     "interligne": 1.5,
 *     "espacements": { "avant_titre": 240, "apres_titre": 120, "apres_paragraphe": 120 },
 *     "alignement_titres": "left",
 *     "alignement_corps": "both",
 *     "marges": { "top": 1440, "right": 1440, "bottom": 1440, "left": 1440, "header": 720, "footer": 720 },
 *     "tableau": { "style": "TableGrid", "header_couleur": "1F3864", "header_texte": "FFFFFF", "bordure": true }
 *   }
 *
 * Les valeurs manquantes sont remplacées par des valeurs par défaut
 * (gabarit "Rapport" académique).
 */
class TemplateStyleResolver
{
    /**
     * Paramètres par défaut (gabarit académique classique).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'police' => 'Times New Roman',
            'tailles' => [
                'titre1' => 16,
                'titre2' => 14,
                'titre3' => 12,
                'corps' => 12,
            ],
            'couleurs' => [
                'titre1' => '1F3864',
                'titre2' => '1F3864',
                'titre3' => '1F3864',
                'corps' => '000000',
            ],
            'interligne' => 1.5,
            'espacements' => [
                'avant_titre' => 240,
                'apres_titre' => 120,
                'apres_paragraphe' => 120,
            ],
            'alignement_titres' => 'left',
            // **Le corps est JUSTIFIÉ par défaut, et c'est une décision.**
            //
            // La clé n'existait pas dans ce resolver — donc `bodyParagraphStyle()`
            // ne portait aucun alignement, et tout le corps des documents sortait
            // aligné à gauche, quels que soient les réglages. Le défaut est
            // désormais `both` (justifié), la convention d'un rapport.
            //
            // L'absence de clé dans un gabarit ENREGISTRÉ (créé avant cette
            // correction) retombe sur ce défaut : les documents déjà traités
            // seront donc justifiés au prochain export, sans migration de données.
            'alignement_corps' => 'both',
            'marges' => [
                'top' => 1440,
                'right' => 1440,
                'bottom' => 1440,
                'left' => 1440,
                'header' => 720,
                'footer' => 720,
            ],
            'tableau' => [
                'style' => 'TableGrid',
                'header_couleur' => '1F3864',
                'header_texte' => 'FFFFFF',
                'bordure' => true,
            ],
        ];
    }

    /**
     * Fusionne les params d'un gabarit avec les défauts.
     *
     * @param  null|array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function normalize(?array $params): array
    {
        $defaults = self::defaults();

        if (! is_array($params)) {
            return $defaults;
        }

        // Fusion récursive limitée aux clés connues
        $merged = $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (array_key_exists($key, $params) && $params[$key] !== null) {
                $merged[$key] = is_array($defaultValue) && is_array($params[$key])
                    ? array_merge($defaultValue, $params[$key])
                    : $params[$key];
            }
        }

        return $merged;
    }

    /**
     * Style de police pour un rôle (titre1, titre2, titre3, corps).
     *
     * @param  array<string, mixed>  $gabarit  Gabarit normalisé (self::normalize)
     * @return array<string, mixed>
     */
    public static function fontStyle(array $gabarit, string $role): array
    {
        $sizes = $gabarit['tailles'] ?? [];
        $colors = $gabarit['couleurs'] ?? [];

        $size = (int) ($sizes[$role] ?? $sizes['corps'] ?? 12);
        $color = (string) ($colors[$role] ?? $colors['corps'] ?? '000000');

        return [
            'name' => (string) ($gabarit['police'] ?? 'Times New Roman'),
            'size' => $size,
            'color' => $color,
            'bold' => in_array($role, ['titre1', 'titre2', 'titre3'], true),
        ];
    }

    /**
     * Style de paragraphe pour les titres (alignement + espacements).
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public static function titleParagraphStyle(array $gabarit): array
    {
        $spacing = $gabarit['espacements'] ?? [];

        return [
            'alignment' => (string) ($gabarit['alignement_titres'] ?? 'left'),
            'spaceBefore' => (int) ($spacing['avant_titre'] ?? 240),
            'spaceAfter' => (int) ($spacing['apres_titre'] ?? 120),
            'lineHeight' => (float) ($gabarit['interligne'] ?? 1.5),
        ];
    }

    /**
     * Style de paragraphe pour le corps (alignement, interligne, espacement après).
     *
     * **L'alignement manquait, et son absence rendait tout réglage inopérant.**
     * Cette méthode ne retournait que l'espacement et l'interligne : PhpWord
     * applique alors son alignement par défaut (à gauche), quel que soit ce que
     * le gabarit dit du corps. Un utilisateur qui choisissait « justifié » — ou
     * qui ne choisissait rien — obtenait le même rendement : un texte ferré à
     * gauche, contraire à la convention d'un rapport.
     *
     * Le défaut est `both` : un contenu de rapport se justifie, et c'est la règle
     * du propriétaire pour TOUS les documents traités. Une valeur vide est
     * traitée comme absente (`?:` et non `??`), sans quoi un champ de formulaire
     * laissé vide écraserait le défaut par une chaîne vide — et PhpWord
     * retomberait silencieusement sur l'alignement à gauche.
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public static function bodyParagraphStyle(array $gabarit): array
    {
        $spacing = $gabarit['espacements'] ?? [];

        return [
            'alignment' => self::alignementCorps($gabarit),
            'spaceAfter' => (int) ($spacing['apres_paragraphe'] ?? 120),
            'lineHeight' => (float) ($gabarit['interligne'] ?? 1.5),
        ];
    }

    /**
     * Alignement du corps, avec repli sur « justifié ».
     *
     * **La valeur « inside » est `both`, pas `justify`.** PhpWord valide
     * l'alignement (`Jc::isValid()`) et n'écrit QUE ses propres constantes :
     * `both` est celle de la justification. `justify` est accepté en entrée mais
     * ne doit pas être ce qu'on ÉCRIT, sans quoi le rendu dépendrait d'un alias
     * plutot que de la valeur documentée.
     *
     * Extrait dans une méthode parce que les listes (`addListItem`) doivent
     * recevoir le MÊME alignement que les paragraphes : deux lectures séparées
     * de la même clé finiraient par diverger, et une liste alignée autrement que
     * le corps se voit immédiatement.
     *
     * @param  array<string, mixed>  $gabarit
     */
    public static function alignementCorps(array $gabarit): string
    {
        $valeur = trim((string) ($gabarit['alignement_corps'] ?? ''));

        return $valeur !== '' ? $valeur : 'both';
    }

    /**
     * Marges de page (twips).
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public static function sectionStyle(array $gabarit): array
    {
        $margins = $gabarit['marges'] ?? [];

        return [
            'marginTop' => (int) ($margins['top'] ?? 1440),
            'marginRight' => (int) ($margins['right'] ?? 1440),
            'marginBottom' => (int) ($margins['bottom'] ?? 1440),
            'marginLeft' => (int) ($margins['left'] ?? 1440),
            'headerHeight' => (int) ($margins['header'] ?? 720),
            'footerHeight' => (int) ($margins['footer'] ?? 720),
        ];
    }

    /**
     * Style d'en-tête de tableau (couleur de fond + texte).
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public static function tableHeaderStyle(array $gabarit): array
    {
        $table = $gabarit['tableau'] ?? [];

        return [
            'fill' => (string) ($table['header_couleur'] ?? '1F3864'),
            'color' => (string) ($table['header_texte'] ?? 'FFFFFF'),
            'bold' => true,
        ];
    }

    /**
     * Style de tableau (bordure + nom de style de base).
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public static function tableStyle(array $gabarit): array
    {
        $table = $gabarit['tableau'] ?? [];
        $border = ! empty($table['bordure']);

        return [
            'borderSize' => $border ? 6 : 0,
            'borderColor' => '000000',
            'cellMargin' => 80,
        ];
    }
}
