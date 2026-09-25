<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Accès aux réglages d'exploitation, et surcharge de la configuration.
 *
 * **Le problème résolu.** Des valeurs qui décident du prix payé par l'utilisateur
 * (marge, coût d'infrastructure, taux de change, montant minimum d'achat) vivaient
 * dans `config/`. Les changer demandait d'éditer un fichier PHP, de commiter et de
 * redéployer. Une décision commerciale prise en réunion exigeait donc un cycle de
 * livraison complet — et en pratique ne se prenait pas.
 *
 * **Pourquoi surcharger `config()` plutôt que lire la base partout.** Le code
 * métier appelle déjà `config('openrouter.cost_margin')` dans
 * `UsageCostCalculator`, `config('kpay.min_amount')` dans le contrôleur, etc.
 * Faire lire la base à chacun de ces points multiplierait les requêtes et
 * disperserait la logique de résolution (que faire si la clé est absente ? d'un
 * type inattendu ?). On surcharge donc la configuration UNE fois, à l'amorçage,
 * et tout le code existant continue de fonctionner sans une ligne de changement.
 *
 * **Le piège du cache.** `config()` n'est rechargé qu'au démarrage de la requête :
 * une valeur modifiée dans l'admin s'applique à la requête suivante, pas à celle
 * en cours. C'est acceptable et attendu. En revanche la valeur est mise en cache
 * applicatif — sans invalidation, une modification mettrait jusqu'à une heure à
 * se voir. `set()` oublie donc explicitement le cache ET la mémoire locale.
 */
class SettingsRepository
{
    /**
     * Clé de cache. Une seule entrée contient TOUS les réglages : la table est
     * petite (quelques dizaines de lignes) et lue à chaque requête, donc une
     * requête unique puis un tableau en mémoire coûtent moins que N requêtes.
     */
    private const CACHE_KEY = 'settings.all';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Types admis pour une valeur.
     *
     * Volontairement restreint : chaque type ajouté est un cas de conversion à
     * gérer, et un type mal converti produit une valeur fausse SANS erreur — un
     * `"0"` devient *truthy*, un `"0.6"` comparé à un float ne correspond jamais.
     * Un réglage qui ne rentre dans aucun de ces types demande un développement,
     * pas une saisie libre.
     *
     * @var array<int, string>
     */
    public const TYPES = ['string', 'int', 'float', 'bool', 'json'];

    /**
     * Réglages chargés en mémoire pour la requête courante.
     *
     * @var array<string, mixed>|null
     */
    /**
     * Mémorisation partagée entre TOUTES les instances du dépôt.
     *
     * **Pourquoi statique, et non une propriété d'instance.** Le dépôt est
     * enregistré en singleton, mais rien n'empêche le conteneur d'en créer une
     * seconde instance — et les tests en résolvent volontairement plusieurs
     * (`app(SettingsRepository::class)` dans le test, une autre instance dans le
     * contrôleur sous test). Avec une mémoire par instance, écrire depuis l'une et
     * lire depuis l'autre renvoyait `null` : `set()` semblait n'avoir aucun effet,
     * et un booléen tout juste désactivé se relisait comme « jamais défini ».
     *
     * La cohérence est rétablie par `flush()`, appelé à la fin de chaque écriture.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $memoire = null;

    /**
     * Tous les réglages, typés, indexés par clé.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (self::$memoire !== null) {
            return self::$memoire;
        }

        if (! $this->tableExists()) {
            return self::$memoire = [];
        }

        /** @var array<string, array{value: string, type: string}> $bruts */
        $bruts = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Setting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $s): array => [
                    $s->key => ['value' => (string) $s->value, 'type' => (string) $s->type],
                ])
                ->all();
        });

        self::$memoire = [];

        foreach ($bruts as $cle => $brut) {
            self::$memoire[$cle] = $this->cast($brut['value'], $brut['type']);
        }

        return self::$memoire;
    }

    /**
     * Valeur d'un réglage, ou la valeur fournie si le réglage n'existe pas.
     *
     * Le repli n'est pas un détail : c'est ce qui garantit qu'une base neuve (ou
     * une table vidée) n'empêche pas l'application de démarrer. Le défaut reste
     * celui de `config/`, qui demeure la référence documentée et versionnée.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $tous = $this->all();

        return array_key_exists($key, $tous) ? $tous[$key] : $default;
    }

    /**
     * Écrit un réglage, en conservant son type.
     *
     * @throws \InvalidArgumentException si le type n'est pas admis
     */
    public function set(string $key, mixed $value, string $type = 'string', ?int $userId = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Type de réglage inconnu : {$type}. Types admis : ".implode(', ', self::TYPES).'.'
            );
        }

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $this->serialize($value, $type),
                'type' => $type,
                'updated_by' => $userId,
            ]
        );

        $this->flush();
    }

    /**
     * Écrit plusieurs réglages d'un coup.
     *
     * **En transaction, et pas réglage par réglage.** Les valeurs d'un même écran
     * se lisent ensemble : appliquer la nouvelle marge sans le nouveau coût
     * d'infrastructure donnerait, le temps de l'enregistrement, un coefficient de
     * rentabilité qui n'a jamais été validé — et une facturation concurrente
     * l'utiliserait pour calculer un prix réel.
     *
     * @param  array<string, array{value: mixed, type: string}>  $reglages
     */
    public function setMany(array $reglages, ?int $userId = null): void
    {
        DB::transaction(function () use ($reglages, $userId): void {
            foreach ($reglages as $key => $definition) {
                $this->set($key, $definition['value'], $definition['type'], $userId);
            }
        });

        $this->flush();
    }

    /**
     * Surcharge la configuration de l'application avec les réglages enregistrés.
     *
     * Chaque réglage porte un nom de configuration (`openrouter.cost_margin`),
     * donc la surcharge est directe et sans table de correspondance à maintenir.
     *
     * Un réglage dont le nom ne correspond à aucune configuration existante est
     * ignoré : c'est le cas d'un réglage propre à une fonctionnalité
     * (`payments.link_ttl_days`) qui n'a pas d'équivalent dans `config/`.
     */
    public function applyToConfig(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        foreach ($this->all() as $key => $value) {
            if (config($key) !== null) {
                config([$key => $value]);
            }
        }
    }

    /**
     * Vide le cache applicatif et la mémoire locale, puis réapplique la surcharge
     * de configuration.
     *
     * **Pourquoi réappliquer la surcharge ici et pas seulement vider le cache.**
     * `config()` n'est surchargé qu'au démarrage de la requête. Après une
     * modification, il porte donc encore les anciennes valeurs : un contrôleur qui
     * relit `config()` dans la MÊME requête obtient la valeur d'avant, et affiche
     * ou calcule un résultat faux — silencieusement, puisque rien ne signale que
     * `config()` est périmé. Réappliquer la surcharge après vidage rend la méthode
     * sûre à appeler depuis n'importe où, y compris après une écriture.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$memoire = null;

        $this->applyToConfig();
    }

    /**
     * La table des réglages existe-t-elle ?
     *
     * **Cette garde est indispensable, pas défensive.** `applyToConfig()` tourne
     * dans le `boot()` du fournisseur de services, donc AVANT que les migrations
     * n'aient pu s'exécuter. Sur une installation neuve, la table est absente au
     * premier démarrage — et sans cette vérification, l'application ne démarrerait
     * pas du tout, ce qui rendrait `php artisan migrate` impossible : on ne
     * pourrait jamais créer la table manquante.
     *
     * **Le résultat n'est PAS mémorisé dans un `static`, et c'est volontaire.**
     * C'était le cas, et c'était un défaut : la réponse est « non » au premier
     * démarrage (migrations pas encore passées), et un `static` la fige pour tout
     * le processus. Tous les appels suivants — y compris ceux d'une même requête
     * après `php artisan migrate`, ou ceux des tests qui migrent APRÈS le boot de
     * l'application — recevaient ce « non » périmé. Conséquence observée :
     * `set()` enregistrait bien en base, mais la relecture renvoyait `null`, comme
     * si le réglage n'avait jamais été écrit.
     *
     * Le coût de la vérification est négligeable : elle n'a lieu qu'à l'amorçage
     * d'une requête et après chaque écriture, jamais en boucle.
     */
    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable $e) {
            // Connexion indisponible (base en cours d'installation, tests sans
            // migration) : on considère la table absente plutôt que de faire
            // échouer le démarrage de l'application entière.
            Log::debug('Réglages : table indisponible, surcharge ignorée.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convertit une valeur stockée vers son type d'origine.
     */
    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Prépare une valeur pour le stockage.
     */
    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'null',
            default => (string) $value,
        };
    }
}
