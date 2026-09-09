<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}backup_artifacts` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requested_by BIGINT UNSIGNED NOT NULL,
        status ENUM('queued','running','ready','failed','expired') NOT NULL DEFAULT 'queued',
        stored_name VARCHAR(96) NULL UNIQUE,
        size_bytes BIGINT UNSIGNED NULL,
        checksum_sha256 CHAR(64) NULL,
        error_message VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        expires_at DATETIME NULL,
        INDEX idx_backup_artifacts_status(status,created_at),
        INDEX idx_backup_artifacts_user(requested_by,created_at),
        FOREIGN KEY(requested_by) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
