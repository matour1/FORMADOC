<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convertit toutes les tables utf8mb3 (utf8 « classique », 3 octets) en
 * utf8mb4 (4 octets).
 *
 * Pourquoi : les messages du chat peuvent contenir des emojis (4 octets). Avec
 * utf8mb3, MySQL rejette l'insertion par « SQLSTATE[HY000]: General error: 1366
 * Incorrect string value » et la réponse de l'IA est perdue.
 *
 * La base est déjà en utf8mb4, mais les tables avaient été créées en utf8mb3
 * (DB_CHARSET=utf8 dans le .env au moment des migrations). On aligne ici
 * l'existant sur la configuration corrigée.
 */
return new class extends Migration
{
    /**
     * Convertit chaque table utf8mb3 en utf8mb4 (structure + données).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tablesToConvert() as $table) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $table
            ));
        }
    }

    /**
     * Retour : aucune (utf8mb4 → utf8mb3 perdrait des données).
     */
    public function down(): void
    {
        // Volontairement vide : rétrograder vers utf8mb3 supprimerait les
        // caractères 4 octets (emojis) déjà enregistrés.
    }

    /**
     * Tables dont la collation de table est encore en utf8mb3.
     *
     * @return array<int, string>
     */
    private function tablesToConvert(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = ?
               AND TABLE_COLLATION LIKE ?
             ORDER BY TABLE_NAME',
            ['BASE TABLE', 'utf8mb3%']
        );

        return array_map(static fn ($row): string => (string) $row->TABLE_NAME, $rows);
    }
};
