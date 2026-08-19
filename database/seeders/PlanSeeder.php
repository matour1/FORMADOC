<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plans d'abonnement FORMADOC.
 *
 * Tarifs mensuels (FCFA) :
 *   - Standard   : 3 000 FCFA/mois
 *   - Premium    : 5 000 FCFA/mois
 *   - Pro        : 8 000 FCFA/mois
 *   - Entreprises: sur devis (price_fcfa = 0, abonnement sans fin)
 *
 * Le plan "default" (gratuit) n'est pas une ligne en base : il correspond
 * à l'absence d'abonnement actif (currentPlanSlug() retourne "default").
 */
class PlanSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $plans = [
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'description' => 'Pour les étudiants et indépendants : IA sur documents, assistance chat de base.',
                'price_fcfa' => 3000,
                'features' => [
                    'Assistant IA sur documents',
                    'Chat IA (modèles économiques)',
                    '5 mises en forme IA / mois',
                    'Support par email',
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'description' => 'Pour les professionnels : modèles IA avancés, mises en forme illimitées.',
                'price_fcfa' => 5000,
                'features' => [
                    'Assistant IA avancé (Claude Sonnet)',
                    'Chat IA modèles premium',
                    'Mises en forme IA illimitées',
                    'Génération d\'images (GPT Image)',
                    'Support prioritaire',
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Pour les cabinets et équipes : modèles de pointe (Claude Opus), fonctionnalités complètes.',
                'price_fcfa' => 8000,
                'features' => [
                    'Modèle de pointe (Claude Opus)',
                    'Tous les modèles premium',
                    'Traitement longue file d\'attente',
                    'API & intégrations',
                    'Support dédié',
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Entreprises',
                'description' => 'Sur devis : volume, personnalisation, sécurité renforcée.',
                'price_fcfa' => 0, // Sur devis
                'features' => [
                    'Volume illimité',
                    'Personnalisation (modèles, gabarits)',
                    'Hébergement dédié possible',
                    'SLA & support dédié',
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
