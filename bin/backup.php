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

$db = (array)Config::get('db', []);
$pdo = Connection::create($db);
$pdo->exec("SET time_zone = '+00:00'");
$service = new BackupService($pdo, (string)($db['prefix'] ?? ''), $root);

$output = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

try {
    $temporary = $service->create();
    $target = $output ?: $root . '/storage/private/imwiki-backup-' . gmdate('Ymd-His') . '.zip';
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Cannot create backup output directory.');
    }
    if (!@rename($temporary, $target)) {
        if (!@copy($temporary, $target)) {
            throw new RuntimeException('Cannot move backup to target path.');
        }
        @unlink($temporary);
    }
    @chmod($target, 0640);
    $verification = $service->verify($target);
    echo json_encode([
        'ok' => $verification['ok'],
        'path' => $target,
        'size' => filesize($target) ?: 0,
        'files' => $verification['files'],
        'metadata' => $verification['metadata'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($verification['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
