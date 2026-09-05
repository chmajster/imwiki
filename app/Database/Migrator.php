<?php
declare(strict_types=1);

namespace ImWiki\Database;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory, private readonly string $prefix = '')
    {
    }

    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $executed = $this->executed();
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM `' . $this->prefix . 'migrations`')->fetchColumn();
        $ran = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $executed, true)) {
                continue;
            }

            $migration = require $file;
            if (is_callable($migration)) {
                $migration($this->pdo, $this->prefix);
            } elseif (is_array($migration)) {
                foreach ($migration as $statement) {
                    $this->pdo->exec(str_replace('{{prefix}}', $this->prefix, (string) $statement));
                }
            } else {
                throw new \RuntimeException('Invalid migration: ' . $name);
            }

            $stmt = $this->pdo->prepare('INSERT INTO `' . $this->prefix . 'migrations` (migration, batch, executed_at) VALUES (?, ?, UTC_TIMESTAMP())');
            $stmt->execute([$name, $batch]);
            $ran[] = $name;
            $executed[] = $name;
        }

        return $ran;
    }

    public function pending(): array
    {
        $this->ensureMigrationsTable();
        $executed = $this->executed();
        return array_values(array_filter(array_map('basename', $this->files()), static fn(string $name): bool => !in_array($name, $executed, true)));
    }

    public function executed(): array
    {
        $this->ensureMigrationsTable();
        return $this->pdo->query('SELECT migration FROM `' . $this->prefix . 'migrations` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'migrations` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL, executed_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
