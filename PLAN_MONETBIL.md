# PLAN_MONETBIL.md — Plan et vérification de l'intégration Monetbil

> **Date** : 2026-10-10
> **Objet** : vérifier l'intégration Monetbil contre la documentation officielle
> (`https://monetbil.company/doc/libraries-sdks/php-core`) et trancher l'écart
> entre la table `payment_services` et la signature.
> **Branche de travail** : `fix/notification-monetbil-csrf-et-montant`
> **État** : le travail Monetbil (~1 265 insertions, 11 fichiers) est **non commité**.

---

## 1. Check — notre code contre la documentation officielle

### 1.1 Conforme

| Élément documenté par Monetbil | SDK officiel | FORMADOC | Verdict |
|---|---|---|---|
| `setServiceKey` / `setServiceSecret` | couple global | config **par service** + repli global | ✅ plus sûr |
| `setWidgetVersion('v2.1')` | v2.1 | `monetbil.widget_version` = `v2.1` | ✅ |
| `amount` / `currency` / `locale` | requis / requis / facultatif | envoyés (`XAF`, `fr`) | ✅ |
| `item_ref` / `payment_ref` / `user` | fournis | envoyés (`CREDIT{id}`, uuid, `user_id`) | ✅ |
| `return_url` / `notify_url` | fournis | `credits.return.monetbil` / `credits.notify` | ✅ |
| `phone`, `country`, `first_name`, `last_name`, `email` | facultatifs | envoyés si fournis (nom découpé) | ✅ |
| `logo` | URL d'image du widget | **omis volontairement** | ✅ choix justifié |
| `checkPayment` (source d'autorité) | `.../payment/v1/checkPayment` | idem (`paymentId`) | ✅ |
| Signature | MD5(secret + valeurs triées par clé) | `signature()` MD5, `ksort` clé | ✅ |
| Statuts `1/0/-1` + testmode `7/8/9` | — | conformes dans `config/monetbil.php` | ✅ |
| Widget JS embarqué (`Monetbil::js()`) | option Exemple 5 | **non utilisé** — redirection (Exemple 1) | ✅ choix valide |
| SSL vérifié | ❌ SDK le désactive | `Http` Laravel, certificats vérifiés | ✅ mieux que le SDK |
| PHP 5.2, `curl` + `json` | — | PHP 8.4, `Http` | ✅ |

### 1.2 À confirmer / écarts

**É1 — Chemin de l'URL widget (à confirmer en recette).**
La doc et le SDK construisent `https://www.monetbil.com/pay/v2.1/{hash}` (GET).
Le code interroge `POST https://www.monetbil.com/widget/v2.1/{serviceKey}` et
attend un JSON `payment_url`. Plausible, mais **jamais prouvé contre le vrai
service** (uniquement `Http::fake`). Seul point dépendant du fournisseur.

**É2 — La table `payment_services` n'alimente pas la signature (défaut réel).**
`PaymentServiceAdminController` enregistre `service_key` / `service_secret` en
base ; `PaymentGatewayRegistry` s'en sert pour dire « configuré / proposable ».
Mais `MonetbilServiceRegistry` (qui **signe réellement**) lit
`config('monetbil.services.*')`, jamais la base.
Conséquence : un exploitant qui renseigne les clés via l'admin voit la
passerelle **proposée**, mais l'initiation échoue
(`aucun service exploitable pour ce palier`). C'est exactement l'échec silencieux
que la règle `billing.md` interdit (« une clé affichée, modifiable, et sans
effet »).

---

## 2. Décision structurante — rôle de `payment_services`

### 2.1 Ce que la table duplique

| Donnée | Table `payment_services` | Mécanisme déjà en place | Verdict |
|---|---|---|---|
| `active` / `visible` | colonnes | réglages `payments.gateways.*` (SettingsCatalog), éditables dans `/admin/settings`, sans redéploiement | **doublon** |
| `service_key` / `service_secret` | colonnes | `monetbil.services.*` en env — **seule source lue par la signature** | **doublon inerte** |

`PaymentGatewayRegistry::estActif()` / `estVisible()` / `estConfigure()` donnent
la **priorité** à une ligne `payment_services` sur les réglages : deux écrans
éditent donc le même état, avec une hiérarchie invisible. Et les identifiants de
la table ne sont lus par personne au moment de signer.

### 2.2 Décision

> **Mise en œuvre le 2026-10-10** — P1 et P2 exécutés, 132 tests verts.

**Consolider sur les deux mécanismes qui fonctionnent déjà, et retirer la
table `payment_services` :**

1. **Identifiants** → restent en env (`monetbil.services.pack_XXXX.{id,key,secret}`) ;
   c'est la seule source lue par la signature, et des secrets n'ont pas à être
   stockés en base ni affichés dans un écran d'administration.
2. **État opérationnel** (`actif` / `visible`) → réglages `payments.gateways.*`,
   déjà en base et éditables via `/admin/settings`.
3. **Supprimer** la table, le modèle, le contrôleur, les routes, la vue et les
   tests de `payment_services`, ainsi que la branche « base » de
   `PaymentGatewayRegistry`.
4. **Verrouiller** par un test d'architecture : `PaymentGatewayRegistry` et
   `MonetbilServiceRegistry` ne doivent plus référencer `PaymentService`.

> **Pourquoi pas l'inverse (la base devient la source des identifiants).**
> Le rattachement palier → service vit dans `billing.credit_packs[].service`
> (configuration de déploiement). Pour que la base signe réellement, il faudrait
> aussi y déplacer ce rattachement — soit une refonte plus large, pour un gain
> nul : la rotation d'un secret se fait déjà sans redéploiement via `.env` et
> `config:clear`. Ajouter des secrets en base aggraverait la surface d'exposition.

---

## 3. Plan d'exécution

### P0 — Fiabiliser le protocole (avant recette) 🔨 **outillé**
- [x] Commande `monetbil:check` livrée (initie un paiement de TEST et rapporte
      `payment_url` ou l'échec ; `--transaction=<id>` interroge `checkPayment`).
- [ ] **À faire en recette**, avec les identifiants réels : lancer
      `php artisan monetbil:check --service=pack_1000` et confirmer le
      `payment_url`. Si échec : tester `/pay/v2.1/{serviceKey}` ou l'appel signé,
      puis documenter la forme retenue dans `MonetbilService::url()`.
- [x] `credits/notify` accepte GET et POST (choix côté Monetbil) — vérifié par test.

### P1 — Trancher et corriger le lien base ↔ signature *(§2.2)* ✅ **fait**
- [x] Retirer `PaymentService` de `PaymentGatewayRegistry`
      (`service()`, `estActif`, `estVisible`, `estConfigure`).
- [x] Supprimer le module admin `payment-services` (contrôleur, routes, vue,
      modèle, migration) et ses tests.
- [x] Purger la référence à `payment-services` dans `layouts/admin.blade.php`.

### P2 — Durcir et tester ✅ **fait**
- [x] Test d'architecture : plus aucune référence à `PaymentService` dans
      `app/` (`PaymentServiceRemovalInvariantTest`).
- [x] Vérifier `estConfigure('kpay')` (`config('kpay.api_key')` /
      `service_key`) — cohérent (`config/kpay.php` déclare `api_key`/`secret_key`).
- [x] Test d'intégration bout en bout `credits/purchase` → `credits/notify`
      (conservé : `MonetbilCreditPurchaseTest`, 132 tests verts sur le lot).

### P3 — Déploiement
- [ ] `.env` production : `MONETBIL_SERVICE_PACK_1000/3000/5000` (clé + secret)
      **ou** clé globale legacy.
- [ ] Worker queue + cron (bloquants B3/B4 de l'état des lieux).

---

## 4. Règle durable

> **Un identifiant de paiement ne se stocke pas dans deux sources.** La source
> qui SIGNE est la source de vérité. Une valeur affichée dans un écran
> d'administration mais lue par aucun code au moment de signer est un mensonge
> d'interface (règle « une clé affichée, modifiable, et sans effet »,
> `billing.md`).
>
> **Enregistrée** dans `.ai/rules/billing.md` (« Un identifiant de paiement n'a
> qu'une source : celle qui SIGNE »), et verrouillée par
> `PaymentServiceRemovalInvariantTest`.
