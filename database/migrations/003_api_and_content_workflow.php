<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}api_tokens` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        token_prefix VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        scopes_json JSON NOT NULL,
        expires_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        INDEX idx_api_tokens_user(user_id,revoked_at,expires_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}approval_history` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        page_version INT UNSIGNED NOT NULL,
        reviewer_id BIGINT UNSIGNED NULL,
        requested_by BIGINT UNSIGNED NOT NULL,
        decision ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
        comment VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL,
        decided_at DATETIME NULL,
        INDEX idx_approval_page(page_id,created_at),
        INDEX idx_approval_reviewer(reviewer_id,decision,created_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(reviewer_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL,
        FOREIGN KEY(requested_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}saved_searches` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(190) NOT NULL,
        query_text VARCHAR(1000) NOT NULL,
        filters_json JSON NULL,
        sort_key VARCHAR(50) NOT NULL DEFAULT 'relevance',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_saved_search_name(user_id,name),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_properties` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        property_key VARCHAR(100) NOT NULL,
        label VARCHAR(150) NOT NULL,
        property_type ENUM('text','number','date','select','user','boolean') NOT NULL,
        value_text TEXT NULL,
        value_number DECIMAL(30,8) NULL,
        value_date DATE NULL,
        value_user_id BIGINT UNSIGNED NULL,
        value_boolean TINYINT(1) NULL,
        options_json JSON NULL,
        updated_by BIGINT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_page_property(page_id,property_key),
        INDEX idx_page_properties_page(page_id),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(value_user_id) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL,
        FOREIGN KEY(updated_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT INTO `{$prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES ('workflow.status_enabled','0',0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_key=setting_key");
};
