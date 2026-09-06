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
$prefix = 'upgrade_';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?");
$stmt->execute([$prefix . '%']);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$oldDir = $root . '/storage/cache/test-migrations-stage2';
if (is_dir($oldDir)) {
    foreach (glob($oldDir . '/*.php') ?: [] as $file) @unlink($file);
} elseif (!@mkdir($oldDir, 0770, true) && !is_dir($oldDir)) {
    throw new RuntimeException('Cannot create temporary migration fixture directory.');
}
for ($i = 1; $i <= 7; $i++) {
    $match = glob($root . '/database/migrations/' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_*.php') ?: [];
    if (count($match) !== 1) throw new RuntimeException('Missing Stage 2 migration fixture: ' . $i);
    if (!copy($match[0], $oldDir . '/' . basename($match[0]))) throw new RuntimeException('Cannot prepare old migration fixture.');
}

try {
    $oldMigrator = new Migrator($pdo, $oldDir, $prefix);
    $oldMigrator->migrate();
    if ($oldMigrator->pending() !== []) throw new RuntimeException('Stage 2 fixture has pending migrations.');

    $now = gmdate('Y-m-d H:i:s');
    $passwordHash = password_hash('Upgrade-Test-Password-123!', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (username,first_name,last_name,email,password_hash,status,language,timezone,theme,created_at,updated_at) VALUES ('upgrade_admin','Upgrade','Admin','upgrade@example.invalid',?,'active','pl','Europe/Warsaw','system',?,?)");
    $stmt->execute([$passwordHash,$now,$now]);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `{$prefix}roles` (name,label,created_at) VALUES ('administrator','Administrator',?)")->execute([$now]);
    $roleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}user_roles` (user_id,role_id) VALUES (?,?)")->execute([$userId,$roleId]);

    $pdo->prepare("INSERT INTO `{$prefix}spaces` (name,space_key,description,owner_id,visibility,created_at,updated_at) VALUES ('Upgrade Space','UPGRADE','Preserve this space',?,'private',?,?)")->execute([$userId,$now,$now]);
    $spaceId = (int)$pdo->lastInsertId();

    $content = '<h1>Upgrade fixture</h1><p>preserve-me-'.bin2hex(random_bytes(8)).'</p>';
    $pdo->prepare("INSERT INTO `{$prefix}pages` (space_id,parent_id,sort_order,title,slug,previous_slug,content,status,restriction_mode,review_date,version_no,author_id,last_editor_id,owner_id,created_at,updated_at) VALUES (?,NULL,0,'Upgrade Page','upgrade-page',NULL,?,'published','inherited',NULL,1,?,?,?, ?,?)")
        ->execute([$spaceId,$content,$userId,$userId,$userId,$now,$now]);
    $pageId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}page_versions` (page_id,version_no,title,content,properties_json,author_id,change_comment,created_at) VALUES (?,1,'Upgrade Page',?,JSON_ARRAY(),?,'fixture',?)")
        ->execute([$pageId,$content,$userId,$now]);
    $pdo->prepare("INSERT INTO `{$prefix}user_preferences` (user_id,dashboard_json,notification_json,updated_at) VALUES (?,JSON_OBJECT('layout','compact'),JSON_OBJECT('email_mode','none'),?)")
        ->execute([$userId,$now]);

    $fullMigrator = new Migrator($pdo, $root . '/database/migrations', $prefix);
    $plan = $fullMigrator->dryRun();
    if (($plan['pending_count'] ?? 0) < 2) throw new RuntimeException('Upgrade dry-run did not detect enterprise migrations.');
    $ran = $fullMigrator->migrate();
    if (count($ran) < 2) throw new RuntimeException('Enterprise migrations were not executed.');
    if ($fullMigrator->pending() !== []) throw new RuntimeException('Pending migrations after upgrade.');
    if (($fullMigrator->state()['status'] ?? null) !== 'idle') throw new RuntimeException('Migration state is not idle after upgrade.');

    $page = $pdo->query("SELECT uuid,title,content,owner_id,status FROM `{$prefix}pages` WHERE id={$pageId}")->fetch();
    if (!$page || $page['title'] !== 'Upgrade Page' || $page['content'] !== $content || (int)$page['owner_id'] !== $userId) {
        throw new RuntimeException('Page data was not preserved during upgrade.');
    }
    if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$page['uuid'])) throw new RuntimeException('Stable UUID was not assigned during upgrade.');

    $space = $pdo->query("SELECT name,space_key,lifecycle,owner_id FROM `{$prefix}spaces` WHERE id={$spaceId}")->fetch();
    if (!$space || $space['name'] !== 'Upgrade Space' || $space['space_key'] !== 'UPGRADE' || (int)$space['owner_id'] !== $userId || $space['lifecycle'] !== 'active') {
        throw new RuntimeException('Space data was not preserved during upgrade.');
    }

    $prefs = $pdo->query("SELECT dashboard_json,notification_json FROM `{$prefix}user_preferences` WHERE user_id={$userId}")->fetch();
    if (!$prefs || !str_contains((string)$prefs['dashboard_json'], 'compact') || !str_contains((string)$prefs['notification_json'], 'none')) {
        throw new RuntimeException('User preferences were not preserved during upgrade.');
    }

    $super = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}roles` WHERE name='super_administrator'")->fetchColumn();
    if ($super !== 1) throw new RuntimeException('Super Administrator role was not created by upgrade.');

    if ($fullMigrator->migrate() !== []) throw new RuntimeException('Upgrade migrations are not idempotent.');

    echo "UPGRADE_OK\n";
} finally {
    foreach (glob($oldDir . '/*.php') ?: [] as $file) @unlink($file);
    @rmdir($oldDir);
}
