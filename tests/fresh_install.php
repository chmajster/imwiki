<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$db = getenv('TEST_DB_NAME') ?: 'imwiki_test';
$user = getenv('TEST_DB_USER') ?: 'imwiki';
$pass = getenv('TEST_DB_PASS') ?: 'imwiki';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$migrator = new Migrator($pdo, $root . '/database/migrations', '');
$ran = $migrator->migrate();
if (count($ran) < 9) {
    throw new RuntimeException('Expected current migration set to contain at least 9 migrations.');
}
if ($migrator->pending() !== []) {
    throw new RuntimeException('Pending migrations after fresh install test.');
}
if (($migrator->state()['status'] ?? null) !== 'idle') {
    throw new RuntimeException('Migration state is not idle after fresh install.');
}

$requiredTables = [
    'users','roles','permissions','groups','spaces','pages','page_versions','attachments','attachment_versions',
    'comments','notifications','api_tokens','jobs','migrations','migration_state','classifications','retention_policies',
    'space_categories','departments','teams','page_locks','page_aliases','page_relations','page_review_history',
    'property_schemas','property_schema_fields','feature_flags','plugins','auth_providers','external_identities',
    'trusted_devices','webhook_deliveries','search_history','page_links','announcements','announcement_dismissals',
    'user_deletion_requests','impersonation_log','backup_records','support_bundles'
];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredTables as $table) {
    if (!in_array($table, $tables, true)) {
        throw new RuntimeException("Missing table: {$table}");
    }
}

$requiredColumns = [
    'pages' => ['uuid','page_type','classification_id','property_schema_id','legal_hold','publish_at','archive_at','expires_at','verified_at'],
    'spaces' => ['category_id','lifecycle','personal_owner_id','storage_quota_bytes','default_classification_id','team_id'],
    'jobs' => ['priority','max_attempts','locked_by','lock_expires_at','dedupe_key','error_code'],
    'tasks' => ['priority','labels_json'],
    'attachments' => ['scan_status','quarantined_at'],
    'attachment_versions' => ['checksum_sha256'],
    'notifications' => ['dedupe_key','email_sent_at'],
    'groups' => ['system','external_source'],
];
foreach ($requiredColumns as $table => $columns) {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column) {
        if (!in_array($column, $actual, true)) {
            throw new RuntimeException("Missing column: {$table}.{$column}");
        }
    }
}

$classificationCount = (int)$pdo->query('SELECT COUNT(*) FROM classifications')->fetchColumn();
if ($classificationCount < 4) {
    throw new RuntimeException('Default classifications were not seeded by migrations.');
}
$featureCount = (int)$pdo->query('SELECT COUNT(*) FROM feature_flags')->fetchColumn();
if ($featureCount < 6) {
    throw new RuntimeException('Default feature flags were not seeded.');
}
$providerCount = (int)$pdo->query("SELECT COUNT(*) FROM auth_providers WHERE provider_key='local' AND provider_type='local'")->fetchColumn();
if ($providerCount !== 1) {
    throw new RuntimeException('Emergency local authentication provider is missing.');
}
$superAdminRole = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE name='super_administrator'")->fetchColumn();
if ($superAdminRole !== 1) {
    throw new RuntimeException('Super Administrator role is missing.');
}

$uuidExpr = $pdo->query("SELECT LOWER(UUID())")->fetchColumn();
if (!is_string($uuidExpr) || !preg_match('/^[0-9a-f-]{36}$/', $uuidExpr)) {
    throw new RuntimeException('Database UUID support check failed.');
}

// Migrator must be idempotent after successful installation.
if ($migrator->migrate() !== []) {
    throw new RuntimeException('Second migration pass was not idempotent.');
}

echo "FRESH_INSTALL_OK\n";
