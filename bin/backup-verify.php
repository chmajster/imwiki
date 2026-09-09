<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/config/config.php') || !is_file($root . '/storage/installed.lock')) {
    fwrite(STDERR, "imWiki is not installed.\n");
    exit(2);
}
require $root . '/bootstrap.php';

use ImWiki\Database\Connection;
use ImWiki\Services\BackupService;
use ImWiki\Support\Config;

$archive = $argv[1] ?? '';
if ($archive === '' || str_starts_with($archive, '--')) {
    fwrite(STDERR, "Usage: php bin/backup-verify.php <backup.zip>\n");
    exit(2);
}

$db = (array)Config::get('db', []);
$pdo = Connection::create($db);
$service = new BackupService($pdo, (string)($db['prefix'] ?? ''), $root);
$result = $service->verify($archive);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
