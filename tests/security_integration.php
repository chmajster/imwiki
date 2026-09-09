<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;
use ImWiki\Security\Crypto;
use ImWiki\Services\PageService;
use ImWiki\Services\PublicShareService;
use ImWiki\Services\SessionService;
use ImWiki\Services\TotpService;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$db = getenv('TEST_DB_NAME') ?: 'imwiki_test';
$user = getenv('TEST_DB_USER') ?: 'imwiki';
$pass = getenv('TEST_DB_PASS') ?: 'imwiki';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$migrator = new Migrator($pdo, $root . '/database/migrations', '');
$migrator->migrate();

$now = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare("INSERT INTO users (username,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)");
$stmt->execute(['security-test','security-test@example.invalid',password_hash('Example-password-123', PASSWORD_DEFAULT),$now,$now]);
$userId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO spaces (name,space_key,owner_id,visibility,created_at,updated_at) VALUES ('Security','SECURITY',?,'logged_in',?,?)");
$stmt->execute([$userId,$now,$now]);
$spaceId = (int)$pdo->lastInsertId();

$setting = $pdo->prepare("INSERT INTO settings (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)");
$setting->execute(['workflow.status_enabled','1']);
$setting->execute(['sharing.public_enabled','1']);

$pageService = new PageService($pdo);
$pageId = $pageService->create($spaceId, null, 'Workflow draft', '<p>Draft content</p>', $userId);
$status = (string)$pdo->query('SELECT status FROM pages WHERE id=' . $pageId)->fetchColumn();
if ($status !== 'draft') {
    throw new RuntimeException('Workflow invariant failed: new page must be draft while workflow is enabled.');
}

$shares = new PublicShareService($pdo);
$blocked = false;
try {
    $shares->create($pageId, $userId, null, null);
} catch (RuntimeException) {
    $blocked = true;
}
if (!$blocked) {
    throw new RuntimeException('Public sharing invariant failed: draft page was shared.');
}

$pdo->prepare("UPDATE pages SET status='published' WHERE id=?")->execute([$pageId]);
$share = $shares->create($pageId, $userId, null, 'share-password');
if (!$shares->resolve((string)$share['token'])) {
    throw new RuntimeException('Published public share could not be resolved.');
}
$pdo->prepare("UPDATE pages SET status='in_review' WHERE id=?")->execute([$pageId]);
if ($shares->resolve((string)$share['token']) !== null) {
    throw new RuntimeException('Existing public share exposed an in-review page.');
}

$pdo->exec('DROP TABLE user_totp');
$totpFailedClosed = false;
try {
    (new TotpService($pdo, '', new Crypto(str_repeat('s', 64))))->enabled($userId);
} catch (PDOException) {
    $totpFailedClosed = true;
}
if (!$totpFailedClosed) {
    throw new RuntimeException('TOTP storage failure did not fail closed.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$pdo->exec('DROP TABLE user_sessions');
if ((new SessionService($pdo))->ensureCurrent($userId, '127.0.0.1', 'security-test-agent')) {
    throw new RuntimeException('Session registry failure did not fail closed.');
}

echo "SECURITY_INTEGRATION_OK\n";
