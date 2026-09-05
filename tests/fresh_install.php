<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Connection;
use ImWiki\Database\Migrator;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$db = getenv('TEST_DB_NAME') ?: 'imwiki_test';
$user = getenv('TEST_DB_USER') ?: 'imwiki';
$pass = getenv('TEST_DB_PASS') ?: 'imwiki';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$migrator = new Migrator($pdo, $root . '/database/migrations', '');
$migrator->migrate();
$required = ['users','roles','spaces','pages','page_versions','attachments','comments','notifications','api_tokens','jobs','migrations'];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($required as $table) {
    if (!in_array($table, $tables, true)) { throw new RuntimeException("Missing table: {$table}"); }
}
if ($migrator->pending() !== []) { throw new RuntimeException('Pending migrations after fresh install test.'); }
echo "FRESH_INSTALL_OK\n";
