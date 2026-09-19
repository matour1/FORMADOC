<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vérifie que la base supporte les caractères 4 octets (utf8mb4).
 *
 * Contexte : les réponses de l'IA et les messages utilisateurs peuvent contenir
 * des emojis. Avec des tables en utf8mb3, MySQL rejetait l'insertion
 * (« SQLSTATE[HY000]: General error: 1366 Incorrect string value ») et la
 * réponse de l'IA était perdue après facturation.
 */
class DatabaseCharsetTest extends TestCase
{
    /**
     * Un emoji (4 octets UTF-8) doit pouvoir être enregistré et relu.
     */
    public function test_un_emoji_peut_etre_enregistre_dans_un_message_de_chat(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Vérification spécifique à MySQL.');
        }

        $emoji = "\u{1F60A}"; // 😊 — 4 octets en UTF-8

        $id = DB::table('chat_messages')->insertGetId([
            'chat_session_id' => null,
            'role' => 'assistant',
            'content' => 'Analyse terminée '.$emoji,
            'cost_credits' => 0,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $content = (string) DB::table('chat_messages')->where('id', $id)->value('content');

        $this->assertStringContainsString($emoji, $content);

        DB::table('chat_messages')->where('id', $id)->delete();
    }

    /**
     * Les colonnes textuelles du chat doivent être en utf8mb4, pas utf8mb3.
     */
    public function test_les_colonnes_du_chat_sont_en_utf8mb4(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Vérification spécifique à MySQL.');
        }

        $columns = DB::select(
            'SELECT COLUMN_NAME, CHARACTER_SET_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CHARACTER_SET_NAME IS NOT NULL',
            ['chat_messages']
        );

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertSame(
                'utf8mb4',
                $column->CHARACTER_SET_NAME,
                sprintf('La colonne %s doit être en utf8mb4.', $column->COLUMN_NAME)
            );
        }
    }

    /**
     * La connexion MySQL doit rester configurée en utf8mb4.
     *
     * Régression visée : DB_CHARSET=utf8 (utf8mb3) dans le .env faisait créer
     * des tables incapables de stocker les emojis (erreur MySQL 1366), ce qui
     * faisait perdre la réponse de l'IA après facturation.
     */
    public function test_la_connexion_mysql_utilise_utf8mb4(): void
    {
        $this->assertSame('utf8mb4', config('database.connections.mysql.charset'));
        $this->assertSame('utf8mb4_unicode_ci', config('database.connections.mysql.collation'));
    }
}
