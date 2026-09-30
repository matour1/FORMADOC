<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Gabarits de mise en forme prédéfinis (priorité n°1 : pipeline complet).
 *
 * Chaque gabarit définit les paramètres de style appliqués lors de la
 * reconstruction du document :
 *   - police (Times New Roman, Arial, Calibri…)
 *   - tailles des titres (Titre 1/2/3) et du corps
 *   - couleurs des titres
 *   - interligne (simple, 1.5, double) + espacements avant/après
 *   - alignement des titres
 *   - marges de page
 *   - style des tableaux (couleur d'en-tête, bordures)
 */
class TemplateSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $templates = [
            [
                'name' => 'Rapport',
                'description' => 'Gabarit académique classique : Times New Roman, titres bleu foncé, interligne 1,5.',
                'params' => [
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
                        'avant_titre' => 240,   // 12 pt avant un titre
                        'apres_titre' => 120,   // 6 pt après un titre
                        'apres_paragraphe' => 120,
                    ],
                    'alignement_titres' => 'left',
                    'alignement_corps' => 'both',
                    'marges' => [
                        'top' => 1440,    // 2,54 cm (pouces = 1" = 1440 twips)
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
                ],
                'is_public' => true,
            ],
            [
                'name' => 'Mémoire',
                'description' => 'Gabarit mémoire : Arial, titres noirs, interligne double, marges larges.',
                'params' => [
                    'police' => 'Arial',
                    'tailles' => [
                        'titre1' => 16,
                        'titre2' => 14,
                        'titre3' => 12,
                        'corps' => 12,
                    ],
                    'couleurs' => [
                        'titre1' => '000000',
                        'titre2' => '000000',
                        'titre3' => '000000',
                        'corps' => '000000',
                    ],
                    'interligne' => 2.0,
                    'espacements' => [
                        'avant_titre' => 240,
                        'apres_titre' => 120,
                        'apres_paragraphe' => 0,
                    ],
                    'alignement_titres' => 'left',
                    'alignement_corps' => 'both',
                    'marges' => [
                        'top' => 1800,
                        'right' => 1800,
                        'bottom' => 1800,
                        'left' => 1800,
                        'header' => 720,
                        'footer' => 720,
                    ],
                    'tableau' => [
                        'style' => 'TableGrid',
                        'header_couleur' => '000000',
                        'header_texte' => 'FFFFFF',
                        'bordure' => true,
                    ],
                ],
                'is_public' => true,
            ],
            [
                'name' => 'Document professionnel',
                'description' => 'Gabarit entreprise : Calibri, titres gris foncé, interligne simple.',
                'params' => [
                    'police' => 'Calibri',
                    'tailles' => [
                        'titre1' => 16,
                        'titre2' => 14,
                        'titre3' => 12,
                        'corps' => 11,
                    ],
                    'couleurs' => [
                        'titre1' => '333333',
                        'titre2' => '333333',
                        'titre3' => '333333',
                        'corps' => '000000',
                    ],
                    'interligne' => 1.0,
                    'espacements' => [
                        'avant_titre' => 200,
                        'apres_titre' => 100,
                        'apres_paragraphe' => 80,
                    ],
                    'alignement_titres' => 'left',
                    'alignement_corps' => 'both',
                    'marges' => [
                        'top' => 1080,
                        'right' => 1080,
                        'bottom' => 1080,
                        'left' => 1080,
                        'header' => 540,
                        'footer' => 540,
                    ],
                    'tableau' => [
                        'style' => 'TableGrid',
                        'header_couleur' => '333333',
                        'header_texte' => 'FFFFFF',
                        'bordure' => true,
                    ],
                ],
                'is_public' => true,
            ],
        ];

        foreach ($templates as $template) {
            Template::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}
