---
paths:
  - 'app/Console/Commands/**'
---

# Console

## Style des commandes
`declare(strict_types=1)` et attributs `#[Signature]` + `#[Description]` (et non les propriétés `$signature`/`$description`).

Renvoie un code de sortie explicite (`self::SUCCESS` / `self::FAILURE`) plutôt que de laisser la valeur de `handle()` décider.

## `user:make-admin` : élévation d'un compte
`is_admin` est **VOLONTAIREMENT** hors de la liste `Fillable` (`#[Fillable]` sur `App\Models\User`) : c'est ce qui garantit qu'aucun formulaire ne peut créer un administrateur (une requête d'inscription contenant `is_admin=1` n'a aucun effet). L'élévation passe donc par `forceFill(['is_admin' => true])` — un contournement explicite, voulu.

**Ne jamais ajouter `is_admin` aux attributs fillable pour « simplifier »** : cela transformerait le contrôle d'accès le plus sensible de l'application en champ de formulaire.

La commande crée le compte s'il n'existe pas (mot de passe généré, affiché **une seule fois** — il est haché en base, donc non récupérable) et, si le compte existe, l'élève **sans toucher à son mot de passe** (le réinitialiser déconnecterait l'utilisateur pour une opération qui ne parle que de droits). Elle est idempotente.

⚠️ Ne pas se fier à `is_admin` seul pour valider un accès : vérifier le comportement observable (un GET sur `/admin` doit répondre 200 pour un admin, 404 pour un non-admin). Les deux niveaux de vérification sont complémentaires — l'élévation peut réussir alors que l'accès échoue (middleware, groupe de routes, cast).
