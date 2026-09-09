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
use ZipArchive;

$archive = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
if ($archive === '' || str_starts_with($archive, '--')) {
    fwrite(STDERR, "Usage: php bin/restore.php <backup.zip> [--apply --force]\n");
    exit(2);
}

$db = (array)Config::get('db', []);
$pdo = Connection::create($db);
$service = new BackupService($pdo, (string)($db['prefix'] ?? ''), $root);
$verification = $service->verify($archive);
if (!$verification['ok']) {
    fwrite(STDERR, json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$backupPrefix = (string)($verification['metadata']['db']['prefix'] ?? '');
$currentPrefix = (string)($db['prefix'] ?? '');
if ($backupPrefix !== $currentPrefix) {
    fwrite(STDERR, "Backup table prefix does not match current configuration.\n");
    exit(3);
}

if (!$apply) {
    echo json_encode([
        'ok' => true,
        'verified' => true,
        'apply' => false,
        'message' => 'Backup verified. Re-run with --apply --force while application traffic is stopped.',
        'metadata' => $verification['metadata'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
if (!$force) {
    fwrite(STDERR, "Restore is destructive. --force is required.\n");
    exit(4);
}

$client = null;
$candidates = PHP_OS_FAMILY === 'Windows'
    ? ['mariadb.exe', 'mysql.exe']
    : ['/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mariadb', '/usr/local/bin/mysql'];
foreach ($candidates as $candidate) {
    if ((str_contains($candidate, '/') && is_executable($candidate)) || (!str_contains($candidate, '/') && getenv('PATH'))) {
        if (str_contains($candidate, '/') || PHP_OS_FAMILY === 'Windows') {
            $client = $candidate;
            break;
        }
    }
}
if ($client === null) {
    fwrite(STDERR, "mysql/mariadb client was not found. Restore was not started.\n");
    exit(5);
}

$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    fwrite(STDERR, "Cannot open backup archive.\n");
    exit(1);
}

$stage = $root . '/storage/private/restore-' . bin2hex(random_bytes(8));
$stageUploads = $stage . '/uploads';
if (!mkdir($stageUploads, 0770, true) && !is_dir($stageUploads)) {
    $zip->close();
    fwrite(STDERR, "Cannot create restore staging directory.\n");
    exit(1);
}
$sqlPath = $stage . '/database.sql';

$cleanup = static function () use ($stage): void {
    if (!is_dir($stage)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($stage);
};

try {
    $sqlStream = $zip->getStream('database.sql');
    if (!is_resource($sqlStream)) {
        throw new RuntimeException('database.sql cannot be read.');
    }
    $sqlOut = fopen($sqlPath, 'wb');
    if (!$sqlOut) {
        fclose($sqlStream);
        throw new RuntimeException('Cannot create staged SQL file.');
    }
    stream_copy_to_stream($sqlStream, $sqlOut);
    fclose($sqlStream);
    fclose($sqlOut);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)($zip->getNameIndex($i) ?: '');
        if (!preg_match('#^uploads/([a-f0-9]{48})$#', $name, $match)) {
            continue;
        }
        $input = $zip->getStream($name);
        if (!is_resource($input)) {
            throw new RuntimeException('Cannot read upload ' . $name);
        }
        $destination = $stageUploads . '/' . $match[1];
        $output = fopen($destination, 'wb');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Cannot stage upload ' . $name);
        }
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        @chmod($destination, 0640);
    }
    $zip->close();

    $command = [
        $client,
        '--host=' . (string)($db['host'] ?? 'localhost'),
        '--port=' . (int)($db['port'] ?? 3306),
        '--user=' . (string)($db['username'] ?? ''),
        '--default-character-set=utf8mb4',
        (string)($db['database'] ?? ''),
    ];
    $environment = $_ENV;
    $environment['MYSQL_PWD'] = (string)($db['password'] ?? '');
    $process = proc_open($command, [['file', $sqlPath, 'rb'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start database client.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Database restore failed: ' . trim((string)$stderr . "\n" . (string)$stdout));
    }

    $uploads = $root . '/storage/uploads';
    if (!is_dir($uploads) && !mkdir($uploads, 0770, true) && !is_dir($uploads)) {
        throw new RuntimeException('Cannot create uploads directory after database restore.');
    }
    foreach (glob($stageUploads . '/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $target = $uploads . '/' . basename($file);
        if (!@copy($file, $target)) {
            throw new RuntimeException('Cannot restore upload ' . basename($file));
        }
        @chmod($target, 0640);
    }

    echo json_encode([
        'ok' => true,
        'restored' => true,
        'metadata' => $verification['metadata'],
        'note' => 'Database and referenced uploads restored. Run php bin/scheduler.php and sign in to validate the installation.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $cleanup();
    exit(0);
} catch (Throwable $e) {
    @$zip->close();
    $cleanup();
    fwrite(STDERR, json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
