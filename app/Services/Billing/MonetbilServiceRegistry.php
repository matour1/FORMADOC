<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Résolution des identifiants Monetbil, service par service.
 *
 * **Le problème résolu.** Monetbil exige un SERVICE déclaré par offre, et chaque
 * service possède SES PROPRES identifiants (`key` et `secret`). Un compte peut en
 * héberger plusieurs. Il ne peut donc pas y avoir un unique couple global : la
 * signature d'un service ne peut pas être vérifiée avec le secret d'un autre.
 *
 * **Pourquoi une classe et non un `config()` dispersé.** Deux endroits ont besoin
 * de ces identifiants, et ils doivent résoudre la MÊME chose :
 *
 *  - l'INITIATION d'un achat, qui construit l'URL du widget et signe la requête
 *    avec la clé du service du palier choisi ;
 *  - la NOTIFICATION entrante, qui doit vérifier la signature avec le secret de CE
 *    service — et le retrouve à partir du montant, qui est signé.
 *
 * Si les deux résolvaient séparément, une divergence ferait signer avec un secret
 * et vérifier avec un autre : toutes les notifications légitimes seraient
 * rejetées, le client serait débité et jamais crédité, sans erreur visible.
 *
 * **Ce que la classe ne fait PAS.** Elle ne devine pas et ne prend pas de défaut
 * silencieux : une référence inconnue renvoie `null`, et l'appelant refuse. Un
 * défaut « au cas où » encaisserait sur le mauvais service.
 */
final class MonetbilServiceRegistry
{
    /**
     * Résout les identifiants du service désigné par une référence de palier.
     *
     * **Deux formes acceptées, et pourquoi.** La configuration des paliers
     * (`billing.credit_packs[].service`) peut contenir soit la CLÉ de configuration
     * du service (`pack_1000`, lisible et stable), soit l'IDENTIFIANT Monetbil
     * lui-même. Les deux désignent le même service ; accepter les deux évite qu'un
     * `.env` existant, qui portait l'identifiant brut, cesse de fonctionner après
     * la séparation des identifiants par service.
     *
     * @return null|array{id: string, key: string, secret: string}
     */
    public function pourReference(?string $reference): ?array
    {
        $reference = is_string($reference) ? trim($reference) : '';

        if ($reference === '') {
            return null;
        }

        $services = (array) config('monetbil.services', []);

        // 1) La référence est la CLÉ de configuration du service.
        if (isset($services[$reference]) && is_array($services[$reference])) {
            return $this->normaliser($services[$reference]);
        }

        // 2) La référence est l'IDENTIFIANT Monetbil du service.
        foreach ($services as $service) {
            if (is_array($service) && ($service['id'] ?? null) === $reference) {
                return $this->normaliser($service);
            }
        }

        // 3) Service inconnu : on refuse. Un défaut permissif encaisserait sur le
        //    mauvais service, ou signerait avec le mauvais secret.
        return null;
    }

    /**
     * Résout les identifiants du service rattaché à un montant de palier.
     *
     * Sert à la NOTIFICATION : elle ne connaît pas la référence de service, mais
     * le montant signé. Le catalogue fait le lien montant → référence, puis la
     * référence est résolue ici.
     *
     * @return null|array{id: string, key: string, secret: string}
     */
    public function pourMontant(int $montant, CreditPackCatalog $catalogue): ?array
    {
        return $this->pourReference($catalogue->serviceDe($montant));
    }

    /**
     * Le service est-il exploitable ? Identifiant, clé ET secret présents.
     *
     * Un service à moitié renseigné (identifiant sans secret) ne peut ni signer
     * une requête ni vérifier une notification : il doit être traité comme absent.
     *
     * @param  null|array{id: string, key: string, secret: string}  $service
     */
    public function estExploitable(?array $service): bool
    {
        return $service !== null
            && $service['id'] !== ''
            && $service['key'] !== ''
            && $service['secret'] !== '';
    }

    /**
     * Normalise une entrée de configuration en identifiants exploitables.
     *
     * Les valeurs sont converties en chaînes et élaguées : un `.env` avec
     * `MONETBIL_SERVICE_PACK_1000_KEY=` produit une chaîne vide, pas `null`, et la
     * confondre avec une valeur renseignée ferait signer avec un secret vide.
     *
     * @param  array<string, mixed>  $service
     * @return array{id: string, key: string, secret: string}
     */
    private function normaliser(array $service): array
    {
        return [
            'id' => (string) ($service['id'] ?? ''),
            'key' => (string) ($service['key'] ?? ''),
            'secret' => (string) ($service['secret'] ?? ''),
        ];
    }
}
