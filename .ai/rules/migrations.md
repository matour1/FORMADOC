---
paths:
  - 'database/migrations/**'
---

# Migrations

## utf8mb4 obligatoire (emojis rejetés en utf8mb3)
La connexion MySQL doit être en `utf8mb4` (`DB_CHARSET=utf8mb4`, `DB_COLLATION=utf8mb4_unicode_ci`). `utf8` = utf8mb3 (3 octets) → MySQL rejette les emojis et tout caractère 4 octets avec « SQLSTATE[HY000]: General error: 1366 Incorrect string value ». Les réponses de l'IA en contiennent souvent, ce qui faisait perdre le message APRÈS facturation des crédits.

Les tables créées avant la correction sont converties par la migration `convert_tables_to_utf8mb4_charset` (`ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`). Toute nouvelle table hérite de la config de connexion : ne jamais remettre `DB_CHARSET=utf8`.

Test de garde : `tests/Feature/DatabaseCharsetTest.php`.
