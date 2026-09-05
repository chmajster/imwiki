<?php
declare(strict_types=1);


return static function (\PDO $pdo, string $prefix): void {
    $table = static fn(string $name): string => $prefix . $name;

    $hasColumn = static function (string $tableName, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$tableName, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $hasIndex = static function (string $tableName, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$tableName, $index]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $spacePermissions = $table('space_permissions');
    if (!$hasColumn($spacePermissions, 'can_attachments')) {
        $pdo->exec("ALTER TABLE `{$spacePermissions}` ADD COLUMN can_attachments TINYINT(1) NOT NULL DEFAULT 0 AFTER can_comment");
    }

    $pages = $table('pages');
    if (!$hasColumn($pages, 'owner_id')) {
        $pdo->exec("ALTER TABLE `{$pages}` ADD COLUMN owner_id BIGINT UNSIGNED NULL AFTER last_editor_id");
        $pdo->exec("UPDATE `{$pages}` SET owner_id=author_id WHERE owner_id IS NULL");
    }
    if (!$hasColumn($pages, 'restriction_mode')) {
        $pdo->exec("ALTER TABLE `{$pages}` ADD COLUMN restriction_mode ENUM('inherited','specific','private') NOT NULL DEFAULT 'inherited' AFTER status");
    }
    if (!$hasColumn($pages, 'review_date')) {
        $pdo->exec("ALTER TABLE `{$pages}` ADD COLUMN review_date DATE NULL AFTER restriction_mode");
    }
    $pdo->exec("ALTER TABLE `{$pages}` MODIFY COLUMN status ENUM('draft','in_review','approved','published','archived') NOT NULL DEFAULT 'published'");
    if (!$hasIndex($pages, 'idx_pages_owner')) {
        $pdo->exec("ALTER TABLE `{$pages}` ADD INDEX idx_pages_owner(owner_id)");
    }

    $audit = $table('audit_log');
    if (!$hasColumn($audit, 'category')) {
        $pdo->exec("ALTER TABLE `{$audit}` ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT 'application' AFTER action");
    }
    if (!$hasColumn($audit, 'severity')) {
        $pdo->exec("ALTER TABLE `{$audit}` ADD COLUMN severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER category");
    }
    if (!$hasColumn($audit, 'request_id')) {
        $pdo->exec("ALTER TABLE `{$audit}` ADD COLUMN request_id VARCHAR(64) NULL AFTER severity");
    }
    if (!$hasColumn($audit, 'metadata_json')) {
        $pdo->exec("ALTER TABLE `{$audit}` ADD COLUMN metadata_json JSON NULL AFTER description");
    }
    if (!$hasIndex($audit, 'idx_audit_request')) {
        $pdo->exec("ALTER TABLE `{$audit}` ADD INDEX idx_audit_request(request_id)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}notifications` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(80) NOT NULL,
        actor_id BIGINT UNSIGNED NULL,
        target_type VARCHAR(40) NULL,
        target_id BIGINT UNSIGNED NULL,
        url VARCHAR(500) NULL,
        payload_json JSON NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_notifications_user_read(user_id,read_at,created_at),
        INDEX idx_notifications_target(target_type,target_id),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE,
        FOREIGN KEY(actor_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}mentions` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL,
        target_type ENUM('page','comment') NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        context_key VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_mention_context(user_id,context_key),
        INDEX idx_mentions_page(page_id,created_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE,
        FOREIGN KEY(actor_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE,
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}tasks` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        description VARCHAR(1000) NOT NULL,
        status ENUM('open','done') NOT NULL DEFAULT 'open',
        assignee_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        due_date DATE NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_tasks_assignee_status(assignee_id,status,due_date),
        INDEX idx_tasks_page(page_id,status),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(assignee_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}attachment_versions` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attachment_id BIGINT UNSIGNED NOT NULL,
        version_no INT UNSIGNED NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime_type VARCHAR(190) NOT NULL,
        size_bytes BIGINT UNSIGNED NOT NULL,
        checksum_sha256 CHAR(64) NULL,
        uploader_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_attachment_version(attachment_id,version_no),
        INDEX idx_attachment_versions_attachment(attachment_id,version_no),
        FOREIGN KEY(attachment_id) REFERENCES `{$prefix}attachments`(id) ON DELETE CASCADE,
        FOREIGN KEY(uploader_id) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $attachments = $table('attachments');
    if (!$hasColumn($attachments, 'current_version')) {
        $pdo->exec("ALTER TABLE `{$attachments}` ADD COLUMN current_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER size_bytes");
    }
    $pdo->exec("INSERT IGNORE INTO `{$prefix}attachment_versions` (attachment_id,version_no,stored_name,mime_type,size_bytes,checksum_sha256,uploader_id,created_at)
        SELECT id,1,stored_name,mime_type,size_bytes,NULL,uploader_id,created_at FROM `{$prefix}attachments`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}favorite_spaces` (
        user_id BIGINT UNSIGNED NOT NULL,
        space_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY(user_id,space_id),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE,
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
