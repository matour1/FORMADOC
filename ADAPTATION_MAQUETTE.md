# Adaptation de la maquette Stitch « Academic Precision »

> **Source** : maquette Google Stitch, 17 écrans + `DESIGN.md` (fournie dans une archive)
> **Objet** : registre des écarts entre la maquette et le backend réel, et règles d'adaptation retenues.
> **Principe directeur** : on adapte la maquette au produit réel, **jamais l'inverse**. Afficher une donnée qui n'existe pas est un échec silencieux : l'écran semble fonctionner tout en mentant.

---

## 1. Ce qui a été appliqué tel quel (identité visuelle)

Le `DESIGN.md` a été traduit dans `resources/css/formadoc.css`, qui sert **79 vues** via **246 classes**. Aucune vue n'a eu besoin d'être réécrite : l'identité passe par les jetons.

| Élément | Avant | Après (maquette) |
|---|---|---|
| Canevas | papier chaud `#FAF9F6` | ardoise froide `#FBFBFA` / `#F8FAFC` |
| Texte principal | encre chaude | ardoise profonde `#0F172A` |
| Action primaire | bleu marine `#2B3F66` | ardoise `#0F172A` |
| Lien / focus / nav active | confondu avec l'action | **cobalt `#2563EB`** (jeton `--color-accent` distinct) |
| Police de corps | Inter | **Plus Jakarta Sans** |
| Ombres | neutres | teintées ardoise, très discrètes |
| Chiffres | non alignés | `tabular-nums` (montants FCFA) |

**Deux jetons ont été ajoutés** au-delà de la maquette, parce qu'un seul jeton ne peut pas porter deux rôles :
- `--color-on-accent` — texte posé sur un fond cobalt (pagination active)
- `--color-on-secondary` — texte posé sur un fond émeraude

Sans eux, l'éclaircissement de ces couleurs en thème sombre rendait le texte illisible (texte blanc sur fond clair).

---

## 2. Écarts entre la maquette et le backend — décisions

### 2.1 Données affichées par la maquette qui n'existent PAS

| Donnée de la maquette | Réalité du backend | Décision |
|---|---|---|
| Opérateurs de paiement (Orange, Wave, MTN, Moov) | `kpay_payments` ne stocke **aucun opérateur** : seulement `payment_id`, `status`, `amount_fcfa`, `metadata` | **Ne pas afficher.** Une colonne « Opérateur » vide, ou déduite du `metadata` quand présent, serait trompeuse |
| Nœuds / régions (Dakar, Abidjan, « Nœud Dakar ») | Aucune notion de nœud ni de région dans le schéma | **Ne pas afficher.** FORMADOC est mono-serveur |
| « Latence IA: 240ms » | Aucune mesure de latence n'est enregistrée | **Ne pas afficher** (ou seulement si le registre d'usage gagne un champ `duration_ms` — il existe par appel, à vérifier) |
| « Console Centrale v2.4 » | Aucune notion de version de console | Remplacer par l'état réel des pipelines (`document.pipeline.v2`) |
| « Parc académique UEMOA » | Aucune segmentation géographique | Formulation neutre |
| Revenus « Ce mois (Août 2026) » | Les revenus existent (`billing.report`, `ai_usage_ledger`) | **Conserver** — donnée réelle, à brancher sur `AdminController::billing()` |
| « Pr. Alassane » (nom d'exemple) | Données d'exemple de la maquette | Utiliser les vraies données utilisateur |

### 2.2 Écrans de la maquette sans équivalent backend

| Écran de la maquette | Équivalent réel | Décision |
|---|---|---|
| `tableau_de_bord_administrateur` | `admin/index.blade.php` ✅ existe | **Adapter** aux vraies données |
| `tableau_de_bord_utilisateur_desktop` | `dashboard.blade.php` ✅ existe | **Adapter** |
| `tableau_de_bord_utilisateur_mobile` | idem, responsive | **Adapter** (le CSS gère déjà le repli) |
| `mes_documents_biblioth_que` | `documents/index.blade.php` ✅ existe | **Adapter** |
| `nouveau_document_mise_en_forme` | `documents/upload.blade.php` ✅ existe | **Adapter** |
| `assistant_ia_chat_documentaire` | `chat/index.blade.php` + `show.blade.php` ✅ | **Adapter** |
| `mon_compte_cr_dits_mobile_money_fcfa` | `account/*.blade.php` ✅ | **Adapter** |
| `mod_les_gabarits_acad_miques` | `templates/index.blade.php` ✅ | **Adapter** |
| `connexion_inscription` | `auth/*.blade.php` ✅ | **Adapter** |
| `accueil_formadoc_dition_acad_mique_cames` | `landing.blade.php` ✅ | **Adapter** |
| **« Page de garde » dans les écrans** | Module **retiré du produit** | **Ne pas réintroduire.** Un test d'invariant (`CoverModuleRemovalInvariantTest`) l'interdit |

---

## 3. Règle d'or à respecter pour chaque écran adapté

1. **Chaque chiffre affiché doit être traçable** jusqu'à une requête ou un calcul existant. Pas de valeur d'exemple qui reste en production.
2. **Une donnée absente est omise**, jamais remplacée par un tiret ou un zéro qui laisserait croire à une mesure.
3. **Un libellé ne promet rien que le produit ne fasse.** Si un écran annonce une action, elle doit exister.
4. **Le thème sombre est vérifié** pour tout nouveau composant (via `--color-on-*`, jamais `#fff` en dur).

---

## 4. État d'avancement

- [x] Identité visuelle appliquée (CSS, polices, jetons de contraste)
- [x] Polices harmonisées sur les 3 layouts (`app`, `admin`, `landing`)
- [x] Ancienne palette purgée du CSS et des vues
- [x] Chiffres tabulaires pour les montants
- [ ] Écrans individuels adaptés aux données réelles
