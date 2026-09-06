<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $hasColumn=static function(string $table,string $column)use($pdo):bool{
        $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$table,$column]);
        return (int)$s->fetchColumn()>0;
    };
    $hasIndex=static function(string $table,string $index)use($pdo):bool{
        $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $s->execute([$table,$index]);
        return (int)$s->fetchColumn()>0;
    };
    $add=static function(string $table,string $column,string $definition)use($pdo,$hasColumn):void{
        if(!$hasColumn($table,$column)){
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
        }
    };

    $jobs=$prefix.'jobs';
    $pdo->exec("ALTER TABLE `{$jobs}` MODIFY COLUMN status ENUM('pending','running','done','failed','dead','discarded') NOT NULL DEFAULT 'pending'");

    $notifications=$prefix.'notifications';
    $add($notifications,'dedupe_key','VARCHAR(190) NULL AFTER payload_json');
    $add($notifications,'email_sent_at','DATETIME NULL AFTER read_at');
    if(!$hasIndex($notifications,'idx_notifications_dedupe')){
        $pdo->exec("ALTER TABLE `{$notifications}` ADD INDEX idx_notifications_dedupe(user_id,dedupe_key,created_at)");
    }
    if(!$hasIndex($notifications,'idx_notifications_email')){
        $pdo->exec("ALTER TABLE `{$notifications}` ADD INDEX idx_notifications_email(user_id,email_sent_at,created_at)");
    }

    // user_preferences is created by migration 005 and intentionally keeps its JSON document shape.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}search_history` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        query_hash CHAR(64) NOT NULL,
        query_text VARCHAR(1000) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_search_history_user(user_id,created_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_links` (
        source_page_id BIGINT UNSIGNED NOT NULL,
        target_page_id BIGINT UNSIGNED NOT NULL,
        link_type ENUM('content','macro') NOT NULL DEFAULT 'content',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY(source_page_id,target_page_id,link_type),
        INDEX idx_page_links_target(target_page_id,source_page_id),
        FOREIGN KEY(source_page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(target_page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}announcements` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scope_type ENUM('global','space') NOT NULL DEFAULT 'global',
        space_id BIGINT UNSIGNED NULL,
        severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
        message VARCHAR(2000) NOT NULL,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        dismissible TINYINT(1) NOT NULL DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_announcement_active(enabled,scope_type,space_id,starts_at,ends_at),
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}announcement_dismissals` (
        announcement_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        dismissed_at DATETIME NOT NULL,
        PRIMARY KEY(announcement_id,user_id),
        FOREIGN KEY(announcement_id) REFERENCES `{$prefix}announcements`(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}user_deletion_requests` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('deactivated','ownership_pending','tasks_pending','personal_space_pending','ready','anonymized','cancelled') NOT NULL DEFAULT 'deactivated',
        replacement_owner_id BIGINT UNSIGNED NULL,
        requested_by BIGINT UNSIGNED NOT NULL,
        notes VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        INDEX idx_user_deletion_status(status,updated_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id),
        FOREIGN KEY(replacement_owner_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL,
        FOREIGN KEY(requested_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}impersonation_log` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_user_id BIGINT UNSIGNED NOT NULL,
        target_user_id BIGINT UNSIGNED NOT NULL,
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        session_fingerprint CHAR(64) NOT NULL,
        request_id VARCHAR(64) NULL,
        INDEX idx_impersonation_admin(admin_user_id,started_at),
        INDEX idx_impersonation_target(target_user_id,started_at),
        FOREIGN KEY(admin_user_id) REFERENCES `{$prefix}users`(id),
        FOREIGN KEY(target_user_id) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}backup_records` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        manifest_json JSON NOT NULL,
        checksum_sha256 CHAR(64) NOT NULL,
        size_bytes BIGINT UNSIGNED NOT NULL,
        encrypted TINYINT(1) NOT NULL DEFAULT 0,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_backup_created(created_at),
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}support_bundles` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        checksum_sha256 CHAR(64) NOT NULL,
        size_bytes BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_support_bundle_expiry(expires_at),
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $settings=[
        'content.max_page_bytes'=>'2097152',
        'content.max_comment_bytes'=>'65536',
        'content.max_property_bytes'=>'10000',
        'notifications.quiet_hours_start'=>'22:00',
        'notifications.quiet_hours_end'=>'07:00',
        'notifications.security_email'=>'1',
        'api.rate_limit_per_minute'=>'120',
        'webhooks.max_attempts'=>'5',
        'jobs.lease_seconds'=>'120',
        'jobs.max_attempts'=>'5',
        'diagnostics.slow_query_ms'=>'500',
        'development.query_profiler'=>'0',
        'system.telemetry'=>'0',
        'updates.enabled'=>'0',
        'updates.endpoint'=>'',
        'storage.temp_max_age_hours'=>'24',
        'support_bundle.retention_hours'=>'24',
        'security.ip_restrictions_enabled'=>'0'
    ];
    $s=$pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_key=setting_key");
    foreach($settings as $k=>$v){
        $s->execute([$k,$v]);
    }
};
