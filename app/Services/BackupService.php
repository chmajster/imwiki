<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class BackupService
{
    private const FORMAT_VERSION = 2;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly string $root,
    ) {}

    public function create(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP_UNAVAILABLE');
        }

        $dir = $this->root . '/storage/private';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('STORAGE');
        }

        $zipPath = tempnam($dir, 'backup-');
        $sqlPath = tempnam($dir, 'db-');
        if ($zipPath === false || $sqlPath === false) {
            throw new RuntimeException('TEMP');
        }

        $zip = new ZipArchive();
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->beginTransaction();

            $this->dumpSql($sqlPath);
            $referencedUploads = $this->referencedUploads();

            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP');
            }

            if (!$zip->addFile($sqlPath, 'database.sql')) {
                throw new RuntimeException('ZIP_SQL');
            }

            $manifestFiles = [
                'database.sql' => $this->fileMeta($sqlPath),
            ];

            $uploadDir = $this->root . '/storage/uploads';
            foreach ($referencedUploads as $storedName) {
                $path = $uploadDir . '/' . $storedName;
                if (!is_file($path)) {
                    throw new RuntimeException('BACKUP_UPLOAD_MISSING:' . $storedName);
                }
                $entry = 'uploads/' . $storedName;
                if (!$zip->addFile($path, $entry)) {
                    throw new RuntimeException('ZIP_UPLOAD:' . $storedName);
                }
                $manifestFiles[$entry] = $this->fileMeta($path);
            }

            $config = require $this->root . '/config/config.php';
            $metadata = [
                'format_version' => self::FORMAT_VERSION,
                'created_at' => gmdate('c'),
                'version' => defined('IMWIKI_VERSION') ? IMWIKI_VERSION : null,
                'app' => [
                    'name' => $config['app']['name'] ?? 'imWiki',
                    'url' => $config['app']['url'] ?? '',
                    'language' => $config['app']['language'] ?? 'pl',
                    'timezone' => $config['app']['timezone'] ?? 'UTC',
                ],
                'db' => [
                    'host' => $config['db']['host'] ?? '',
                    'port' => $config['db']['port'] ?? 3306,
                    'database' => $config['db']['database'] ?? '',
                    'prefix' => $config['db']['prefix'] ?? '',
                ],
                'upload_count' => count($referencedUploads),
            ];
            $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $zip->addFromString('metadata.json', $metadataJson);
            $manifestFiles['metadata.json'] = [
                'sha256' => hash('sha256', $metadataJson),
                'size' => strlen($metadataJson),
            ];

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'algorithm' => 'sha256',
                'files' => $manifestFiles,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $zip->addFromString(
                'RESTORE.txt',
                "Najpierw zweryfikuj archiwum: php bin/backup-verify.php <backup.zip>\n" .
                "Kontrolowany restore offline: php bin/restore.php <backup.zip> --apply --force\n" .
                "Restore wymaga klienta mysql lub mariadb w PATH i korzysta z bieżącego config/config.php.\n" .
                "Przed odtworzeniem zatrzymaj ruch do aplikacji i wykonaj dodatkową kopię bieżących danych.\n"
            );

            if (!$zip->close()) {
                throw new RuntimeException('ZIP_CLOSE');
            }
            $this->pdo->commit();
            @unlink($sqlPath);

            $verification = $this->verify($zipPath);
            if (!$verification['ok']) {
                @unlink($zipPath);
                throw new RuntimeException('BACKUP_VERIFY_FAILED:' . implode(',', $verification['errors']));
            }

            return $zipPath;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($zip->status === ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($sqlPath);
            @unlink($zipPath);
            throw $e;
        }
    }

    /** @return array{ok:bool,errors:list<string>,metadata:array<string,mixed>,files:int} */
    public function verify(string $zipPath): array
    {
        $errors = [];
        $metadata = [];
        if (!is_file($zipPath) || !class_exists(ZipArchive::class)) {
            return ['ok' => false, 'errors' => ['archive_unavailable'], 'metadata' => [], 'files' => 0];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'errors' => ['archive_invalid'], 'metadata' => [], 'files' => 0];
        }

        try {
            foreach (['database.sql', 'metadata.json', 'manifest.json'] as $required) {
                if ($zip->locateName($required) === false) {
                    $errors[] = 'missing:' . $required;
                }
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'metadata' => [], 'files' => $zip->numFiles];
            }

            $metadataRaw = $zip->getFromName('metadata.json');
            $manifestRaw = $zip->getFromName('manifest.json');
            if (!is_string($metadataRaw) || !is_string($manifestRaw)) {
                return ['ok' => false, 'errors' => ['metadata_or_manifest_unreadable'], 'metadata' => [], 'files' => $zip->numFiles];
            }

            $metadata = json_decode($metadataRaw, true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || !is_array($manifest) || (int)($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION) {
                $errors[] = 'unsupported_manifest';
            }

            $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
            foreach ($files as $entry => $expected) {
                if (!is_string($entry) || !$this->safeEntryName($entry) || !is_array($expected)) {
                    $errors[] = 'unsafe_manifest_entry';
                    continue;
                }
                $stat = $zip->statName($entry);
                if ($stat === false) {
                    $errors[] = 'missing:' . $entry;
                    continue;
                }
                $expectedSize = (int)($expected['size'] ?? -1);
                if ((int)$stat['size'] !== $expectedSize) {
                    $errors[] = 'size:' . $entry;
                    continue;
                }
                $digest = $this->zipEntryHash($zip, $entry);
                if (!hash_equals((string)($expected['sha256'] ?? ''), $digest)) {
                    $errors[] = 'checksum:' . $entry;
                }
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)($zip->getNameIndex($i) ?: '');
                if ($name !== '' && !$this->safeEntryName($name)) {
                    $errors[] = 'unsafe_archive_entry:' . $name;
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'verification_exception:' . get_class($e);
        } finally {
            $zip->close();
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'metadata' => is_array($metadata) ? $metadata : [], 'files' => $zip->numFiles ?? 0];
    }

    private function dumpSql(string $path): void
    {
        $handle = fopen($path, 'wb');
        if (!$handle) {
            throw new RuntimeException('SQL_TEMP');
        }

        try {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $table = (string)$table;
                if (!str_starts_with($table, $this->prefix)) {
                    continue;
                }
                $escapedTable = str_replace('`', '``', $table);
                $create = $this->pdo->query('SHOW CREATE TABLE `' . $escapedTable . '`')->fetch(PDO::FETCH_NUM);
                if (!$create) {
                    continue;
                }
                fwrite($handle, "DROP TABLE IF EXISTS `{$escapedTable}`;\n" . $create[1] . ";\n");
                $stmt = $this->pdo->query('SELECT * FROM `' . $escapedTable . '`');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols = array_map(static fn($column) => '`' . str_replace('`', '``', (string)$column) . '`', array_keys($row));
                    $vals = [];
                    foreach ($row as $value) {
                        $vals[] = $value === null ? 'NULL' : $this->pdo->quote((string)$value);
                    }
                    fwrite($handle, 'INSERT INTO `' . $escapedTable . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                }
            }
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function referencedUploads(): array
    {
        $names = [];
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tableSet = array_fill_keys(array_map('strval', $tables), true);

        if (isset($tableSet[$this->prefix . 'attachment_versions'])) {
            $stmt = $this->pdo->query("SELECT stored_name FROM `{$this->prefix}attachment_versions`");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (is_string($name) && preg_match('/^[a-f0-9]{48}$/', $name)) {
                    $names[$name] = true;
                }
            }
        } elseif (isset($tableSet[$this->prefix . 'attachments'])) {
            $stmt = $this->pdo->query("SELECT stored_name FROM `{$this->prefix}attachments` WHERE deleted_at IS NULL");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (is_string($name) && preg_match('/^[a-f0-9]{48}$/', $name)) {
                    $names[$name] = true;
                }
            }
        }

        $result = array_keys($names);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array{sha256:string,size:int} */
    private function fileMeta(string $path): array
    {
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($hash === false || $size === false) {
            throw new RuntimeException('FILE_META');
        }
        return ['sha256' => $hash, 'size' => (int)$size];
    }

    private function zipEntryHash(ZipArchive $zip, string $entry): string
    {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new RuntimeException('ZIP_ENTRY_READ:' . $entry);
        }
        $context = hash_init('sha256');
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('ZIP_ENTRY_READ:' . $entry);
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }
        return hash_final($context);
    }

    private function safeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return false;
        }
        $normalized = str_replace('\\', '/', $name);
        if (preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
            return false;
        }
        return !preg_match('/^[A-Za-z]:\//', $normalized);
    }
}
