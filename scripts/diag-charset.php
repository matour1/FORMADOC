<?php

/**
 * Vérifie le charset/collation des tables et colonnes textuelles du chat
 * (diagnostic de l'erreur 1366 « Incorrect string value » sur les emojis).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$db = DB::select('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
echo 'Base : '.json_encode($db[0]).PHP_EOL;

$tables = ['chat_messages', 'chat_sessions', 'documents', 'users'];
foreach ($tables as $table) {
    $r = DB::select('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    echo $table.' : '.($r[0]->TABLE_COLLATION ?? 'absente').PHP_EOL;
}

echo PHP_EOL.'--- Colonnes textuelles de chat_messages ---'.PHP_EOL;
$cols = DB::select(
    'SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME, DATA_TYPE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME IS NOT NULL',
    ['chat_messages']
);
foreach ($cols as $c) {
    echo sprintf("  %-20s %-10s %-22s %s\n", $c->COLUMN_NAME, $c->DATA_TYPE, $c->CHARACTER_SET_NAME, $c->COLLATION_NAME);
}

echo PHP_EOL.'--- Connexion ---'.PHP_EOL;
echo 'charset config : '.config('database.connections.mysql.charset').PHP_EOL;
echo 'collation config : '.config('database.connections.mysql.collation').PHP_EOL;
