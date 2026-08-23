<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plans d'abonnement FORMADOC.
 *
 * Tarifs mensuels (FCFA) — barème révisé (exigence D du cahier des charges) :
 *   - Gratuit   : 0 FCFA     /  5 docs déterministes /  0 IA
 *   - Standard  : 3 000 FCFA / 10 docs déterministes /  5 IA
 *   - Premium   : 5 000 FCFA / 30 docs déterministes / 15 IA
 *   - Pro       : 13 500 FCFA / illimité (null)      / 90 IA
 *   - Entreprises: sur devis (price_fcfa = 0, abonnement sans fin, illimité)
 *
 * Le plan "default" (gratuit) n'est pas une ligne en base : il correspond
 * à l'absence d'abonnement actif (currentPlanSlug() retourne "default").
 * Ses quotas sont définis dans QuotaService (5 déterministes / 0 IA).
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
                'quota_deterministic' => 10,
                'quota_ai' => 5,
                'quota_period' => 'monthly',
                'features' => [
                    '10 documents traités / mois',
                    '5 traitements IA / mois',
                    'Chat IA (modèles économiques)',
                    'Assistant IA sur documents',
                    'Support par email',
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'description' => 'Pour les mémoires et gros rapports : modèles IA avancés, génération d\'images, support prioritaire.',
                'price_fcfa' => 5000,
                'quota_deterministic' => 30,
                'quota_ai' => 15,
                'quota_period' => 'monthly',
                'features' => [
                    '30 documents traités / mois',
                    '15 traitements IA / mois',
                    'Chat IA modèles premium',
                    'Assistant IA avancé (Claude Sonnet)',
                    'Génération d\'images (GPT Image)',
                    'Support prioritaire',
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Pour les cabinets et équipes : usage illimité des documents, 90 traitements IA/mois, modèles de pointe.',
                'price_fcfa' => 13500,
                'quota_deterministic' => null, // Illimité
                'quota_ai' => 90,
                'quota_period' => 'monthly',
                'features' => [
                    'Documents traités illimités',
                    '90 traitements IA / mois',
                    'Modèle de pointe (Claude Opus)',
                    'Tous les modèles premium',
                    'Skills documentaires Claude (expérimental)',
                    'Traitement longue file d\'attente',
                    'Accès API (sur demande)',
                    'Support dédié',
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Entreprises',
                'description' => 'Sur devis : volume, personnalisation, sécurité renforcée.',
                'price_fcfa' => 0, // Sur devis
                'quota_deterministic' => null, // Illimité
                'quota_ai' => null, // Illimité
                'quota_period' => 'monthly',
                'features' => [
                    'Volume illimité',
                    'Traitements IA illimités',
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
