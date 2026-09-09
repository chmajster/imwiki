<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;
use ImWiki\Services\BackupService;
use ZipArchive;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'imwiki_test';
$user = getenv('TEST_DB_USER') ?: 'imwiki';
$pass = getenv('TEST_DB_PASS') ?: 'imwiki';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
(new Migrator($pdo, $root . '/database/migrations', ''))->migrate();

$pdo->exec("INSERT INTO users (username,first_name,last_name,email,password_hash,status,language,timezone,theme,created_at,updated_at) VALUES ('backup-user','Backup','User','backup@example.invalid','x','active','pl','Europe/Warsaw','system',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$uid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO spaces (name,space_key,owner_id,visibility,created_at,updated_at) VALUES ('Backup','BACKUP',?,'logged_in',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$uid]);
$sid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO pages (space_id,title,slug,content,status,version_no,author_id,last_editor_id,owner_id,restriction_mode,created_at,updated_at) VALUES (?,'Backup page','backup-page','<p>snapshot</p>','published',1,?,?,?,'inherited',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$sid,$uid,$uid,$uid]);
$pageId = (int)$pdo->lastInsertId();

$stored = str_repeat('a', 48);
$uploads = $root . '/storage/uploads';
if (!is_dir($uploads)) { mkdir($uploads, 0770, true); }
$filePath = $uploads . '/' . $stored;
file_put_contents($filePath, 'backup-payload');
$pdo->prepare("INSERT INTO attachments (page_id,uploader_id,original_name,stored_name,mime_type,size_bytes,current_version,created_at) VALUES (?,?,'payload.txt',?,'text/plain',14,1,UTC_TIMESTAMP())")->execute([$pageId,$uid,$stored]);
$attachmentId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO attachment_versions (attachment_id,version_no,stored_name,mime_type,size_bytes,checksum_sha256,uploader_id,created_at) VALUES (?,1,?,'text/plain',14,?, ?,UTC_TIMESTAMP())")->execute([$attachmentId,$stored,hash('sha256','backup-payload'),$uid]);

$configPath = $root . '/config/config.php';
$existingConfig = is_file($configPath) ? file_get_contents($configPath) : null;
$config = [
    'app' => ['name'=>'imWiki Test','url'=>'http://localhost','language'=>'pl','timezone'=>'Europe/Warsaw','secret'=>str_repeat('s',64)],
    'db' => ['host'=>$host,'port'=>$port,'database'=>$dbName,'username'=>$user,'password'=>$pass,'prefix'=>''],
];
file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

$backup = null;
try {
    if (!defined('IMWIKI_VERSION')) { define('IMWIKI_VERSION', trim((string)file_get_contents($root . '/VERSION'))); }
    $service = new BackupService($pdo, '', $root);
    $backup = $service->create();
    $verification = $service->verify($backup);
    if (!$verification['ok']) { throw new RuntimeException('Fresh backup verification failed: ' . implode(',', $verification['errors'])); }
    if (($verification['metadata']['upload_count'] ?? null) !== 1) { throw new RuntimeException('Backup upload count mismatch.'); }

    $zip = new ZipArchive();
    if ($zip->open($backup) !== true) { throw new RuntimeException('Cannot inspect generated backup.'); }
    $sql = $zip->getFromName('database.sql');
    $payload = $zip->getFromName('uploads/' . $stored);
    $manifest = $zip->getFromName('manifest.json');
    $zip->close();
    if (!is_string($sql) || !str_contains($sql, 'backup-page') || !str_contains($sql, 'CREATE TABLE')) { throw new RuntimeException('Database snapshot is incomplete.'); }
    if ($payload !== 'backup-payload') { throw new RuntimeException('Referenced upload is missing from backup.'); }
    if (!is_string($manifest) || !str_contains($manifest, 'sha256')) { throw new RuntimeException('Checksum manifest is missing.'); }

    $zip = new ZipArchive();
    if ($zip->open($backup) !== true) { throw new RuntimeException('Cannot reopen backup for tamper test.'); }
    $zip->addFromString('metadata.json', '{"tampered":true}');
    $zip->close();
    $tampered = $service->verify($backup);
    if ($tampered['ok']) { throw new RuntimeException('Tampered backup passed verification.'); }

    echo "BACKUP_INTEGRITY_OK\n";
} finally {
    if ($backup && is_file($backup)) { @unlink($backup); }
    @unlink($filePath);
    if ($existingConfig !== null) { file_put_contents($configPath, $existingConfig); } else { @unlink($configPath); }
}
