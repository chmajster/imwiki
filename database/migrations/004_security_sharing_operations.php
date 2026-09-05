<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $table=static fn(string $name):string=>$prefix.$name;
    $hasColumn=static function(string $table,string $column)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;
    };

    $pages=$table('pages');
    if(!$hasColumn($pages,'previous_slug')){
        $pdo->exec("ALTER TABLE `{$pages}` ADD COLUMN previous_slug VARCHAR(255) NULL AFTER slug");
    }
    $spaces=$table('spaces');
    if(!$hasColumn($spaces,'homepage_page_id')){
        $pdo->exec("ALTER TABLE `{$spaces}` ADD COLUMN homepage_page_id BIGINT UNSIGNED NULL AFTER owner_id");
        $pdo->exec("ALTER TABLE `{$spaces}` ADD CONSTRAINT fk_spaces_homepage FOREIGN KEY(homepage_page_id) REFERENCES `{$pages}`(id) ON DELETE SET NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_redirects` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        space_id BIGINT UNSIGNED NOT NULL,
        old_slug VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_page_redirect(space_id,old_slug),
        INDEX idx_page_redirect_page(page_id),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}public_shares` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        token_prefix VARCHAR(16) NOT NULL,
        password_hash VARCHAR(255) NULL,
        expires_at DATETIME NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_public_shares_page(page_id,revoked_at,expires_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}password_reset_tokens` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_password_reset_user(user_id,expires_at,used_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}user_sessions` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        session_key_hash CHAR(64) NOT NULL UNIQUE,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        INDEX idx_user_sessions_user(user_id,revoked_at,last_seen_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}login_history` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        login_identifier VARCHAR(190) NOT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        success TINYINT(1) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_login_history_user(user_id,created_at),
        INDEX idx_login_history_created(created_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}user_totp` (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        secret_encrypted TEXT NOT NULL,
        enabled_at DATETIME NULL,
        confirmed_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}recovery_codes` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        code_hash CHAR(64) NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_recovery_code(user_id,code_hash),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}comment_reactions` (
        comment_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        reaction ENUM('like','thanks','confirm') NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY(comment_id,user_id,reaction),
        FOREIGN KEY(comment_id) REFERENCES `{$prefix}comments`(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_reactions` (
        page_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        reaction ENUM('helpful','like') NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY(page_id,user_id,reaction),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}inline_comments` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        page_version INT UNSIGNED NOT NULL,
        quote_text VARCHAR(1000) NOT NULL,
        context_before VARCHAR(500) NULL,
        context_after VARCHAR(500) NULL,
        body TEXT NOT NULL,
        status ENUM('open','resolved','orphaned') NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_inline_page(page_id,status,created_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}jobs` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_type VARCHAR(100) NOT NULL,
        payload_json JSON NOT NULL,
        status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL,
        reserved_at DATETIME NULL,
        finished_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_jobs_ready(status,available_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}webhooks` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        space_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(150) NOT NULL,
        endpoint_url VARCHAR(1000) NOT NULL,
        secret_hash CHAR(64) NOT NULL,
        secret_encrypted TEXT NOT NULL,
        events_json JSON NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_webhooks_space(space_id,enabled),
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT INTO `{$prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES
        ('sharing.public_enabled','0',0,UTC_TIMESTAMP()),
        ('maintenance.enabled','0',0,UTC_TIMESTAMP()),
        ('retention.trash_days','0',0,UTC_TIMESTAMP()),
        ('retention.notifications_days','180',0,UTC_TIMESTAMP()),
        ('retention.login_history_days','365',0,UTC_TIMESTAMP()),
        ('security.require_2fa_roles','[]',0,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE setting_key=setting_key");
};
