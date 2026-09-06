<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $hasColumn=static function(string $table,string $column)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;
    };
    $hasIndex=static function(string $table,string $index)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table,$index]);return (int)$stmt->fetchColumn()>0;
    };
    $add=static function(string $table,string $column,string $definition)use($pdo,$hasColumn):void{
        if(!$hasColumn($table,$column))$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}classifications` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        classification_key VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_public TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO `{$prefix}classifications` (classification_key,name,sort_order,is_public,enabled,created_at,updated_at) VALUES
        ('public','Public',10,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('internal','Internal',20,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('confidential','Confidential',30,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('restricted','Restricted',40,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE name=VALUES(name),sort_order=VALUES(sort_order)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}retention_policies` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        scope ENUM('global','space','classification') NOT NULL DEFAULT 'global',
        archive_after_days INT UNSIGNED NULL,
        delete_after_days INT UNSIGNED NULL,
        keep_forever TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}space_categories` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}departments` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        description VARCHAR(500) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}teams` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        department_id BIGINT UNSIGNED NULL,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(500) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_team_department(department_id,name),
        FOREIGN KEY(department_id) REFERENCES `{$prefix}departments`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $groups=$prefix.'groups';
    $add($groups,'system','TINYINT(1) NOT NULL DEFAULT 0 AFTER label');
    $add($groups,'external_source','VARCHAR(80) NULL AFTER system');

    $users=$prefix.'users';
    $add($users,'avatar_path','VARCHAR(500) NULL AFTER email');
    $add($users,'department_id','BIGINT UNSIGNED NULL AFTER timezone');
    $add($users,'team_id','BIGINT UNSIGNED NULL AFTER department_id');
    if(!$hasIndex($users,'idx_users_department'))$pdo->exec("ALTER TABLE `{$users}` ADD INDEX idx_users_department(department_id), ADD INDEX idx_users_team(team_id)");

    $spaces=$prefix.'spaces';
    $add($spaces,'category_id','BIGINT UNSIGNED NULL AFTER description');
    $add($spaces,'lifecycle','VARCHAR(32) NOT NULL DEFAULT \'active\' AFTER visibility');
    $add($spaces,'review_date','DATE NULL AFTER lifecycle');
    $add($spaces,'retention_policy_id','BIGINT UNSIGNED NULL AFTER review_date');
    $add($spaces,'archive_date','DATETIME NULL AFTER archived_at');
    $add($spaces,'personal_owner_id','BIGINT UNSIGNED NULL AFTER owner_id');
    $add($spaces,'storage_quota_bytes','BIGINT UNSIGNED NULL AFTER personal_owner_id');
    $add($spaces,'default_classification_id','BIGINT UNSIGNED NULL AFTER storage_quota_bytes');
    $add($spaces,'team_id','BIGINT UNSIGNED NULL AFTER default_classification_id');
    if(!$hasIndex($spaces,'idx_spaces_category'))$pdo->exec("ALTER TABLE `{$spaces}` ADD INDEX idx_spaces_category(category_id,lifecycle), ADD INDEX idx_spaces_personal(personal_owner_id), ADD INDEX idx_spaces_team(team_id)");

    $pages=$prefix.'pages';
    $pdo->exec("ALTER TABLE `{$pages}` MODIFY COLUMN status ENUM('draft','in_review','approved','published','deprecated','archived','expired') NOT NULL DEFAULT 'published'");
    $add($pages,'uuid','CHAR(36) NULL AFTER id');
    $pdo->exec("UPDATE `{$pages}` SET uuid=UUID() WHERE uuid IS NULL OR uuid=''");
    if(!$hasIndex($pages,'uq_pages_uuid'))$pdo->exec("ALTER TABLE `{$pages}` ADD UNIQUE KEY uq_pages_uuid(uuid)");
    $add($pages,'page_type','VARCHAR(50) NOT NULL DEFAULT \'page\' AFTER restriction_mode');
    $add($pages,'classification_id','BIGINT UNSIGNED NULL AFTER page_type');
    $add($pages,'legal_hold','TINYINT(1) NOT NULL DEFAULT 0 AFTER classification_id');
    $add($pages,'legal_hold_by','BIGINT UNSIGNED NULL AFTER legal_hold');
    $add($pages,'legal_hold_at','DATETIME NULL AFTER legal_hold_by');
    $add($pages,'deprecated_reason','VARCHAR(1000) NULL AFTER legal_hold_at');
    $add($pages,'replaced_by_page_id','BIGINT UNSIGNED NULL AFTER deprecated_reason');
    $add($pages,'deprecated_at','DATETIME NULL AFTER replaced_by_page_id');
    $add($pages,'publish_at','DATETIME NULL AFTER review_date');
    $add($pages,'archive_at','DATETIME NULL AFTER publish_at');
    $add($pages,'expires_at','DATETIME NULL AFTER archive_at');
    $add($pages,'review_interval_days','INT UNSIGNED NULL AFTER expires_at');
    $add($pages,'next_review_at','DATETIME NULL AFTER review_interval_days');
    $add($pages,'last_reviewed_at','DATETIME NULL AFTER next_review_at');
    $add($pages,'last_reviewed_by','BIGINT UNSIGNED NULL AFTER last_reviewed_at');
    $add($pages,'review_note','VARCHAR(1000) NULL AFTER last_reviewed_by');
    $add($pages,'verified_at','DATETIME NULL AFTER review_note');
    $add($pages,'verified_by','BIGINT UNSIGNED NULL AFTER verified_at');
    if(!$hasIndex($pages,'idx_pages_governance'))$pdo->exec("ALTER TABLE `{$pages}` ADD INDEX idx_pages_governance(status,owner_id,review_date,next_review_at), ADD INDEX idx_pages_classification(classification_id,legal_hold), ADD INDEX idx_pages_schedule(publish_at,archive_at,expires_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_locks` (
        page_id BIGINT UNSIGNED PRIMARY KEY,
        owner_id BIGINT UNSIGNED NOT NULL,
        lock_type ENUM('manual','maintenance','workflow') NOT NULL,
        reason VARCHAR(500) NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_page_locks_expiry(expires_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(owner_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_aliases` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        alias VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_page_alias(page_id,alias),
        INDEX idx_page_alias(alias),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_relations` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_page_id BIGINT UNSIGNED NOT NULL,
        target_page_id BIGINT UNSIGNED NOT NULL,
        relation_type ENUM('related_to','depends_on','replaces','duplicated_by') NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_page_relation(source_page_id,target_page_id,relation_type),
        INDEX idx_page_relations_target(target_page_id,relation_type),
        FOREIGN KEY(source_page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(target_page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}page_review_history` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT UNSIGNED NOT NULL,
        reviewer_id BIGINT UNSIGNED NOT NULL,
        note VARCHAR(1000) NULL,
        reviewed_at DATETIME NOT NULL,
        next_review_at DATETIME NULL,
        INDEX idx_review_history_page(page_id,reviewed_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(reviewer_id) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}property_schemas` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        space_id BIGINT UNSIGNED NULL,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(500) NULL,
        version_no INT UNSIGNED NOT NULL DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_property_schema(space_id,name,version_no),
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}property_schema_fields` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        schema_id BIGINT UNSIGNED NOT NULL,
        field_key VARCHAR(100) NOT NULL,
        label VARCHAR(150) NOT NULL,
        field_type ENUM('text','textarea','integer','decimal','date','datetime','select','multiselect','user','group','boolean','url') NOT NULL,
        required TINYINT(1) NOT NULL DEFAULT 0,
        options_json JSON NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_schema_field(schema_id,field_key),
        FOREIGN KEY(schema_id) REFERENCES `{$prefix}property_schemas`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $add($pages,'property_schema_id','BIGINT UNSIGNED NULL AFTER classification_id');

    $pageProperties=$prefix.'page_properties';
    $pdo->exec("ALTER TABLE `{$pageProperties}` MODIFY COLUMN property_type ENUM('text','textarea','integer','decimal','number','date','datetime','select','multiselect','user','group','boolean','url') NOT NULL");
    $add($pageProperties,'schema_field_id','BIGINT UNSIGNED NULL AFTER page_id');
    $add($pageProperties,'value_datetime','DATETIME NULL AFTER value_date');
    $add($pageProperties,'value_group_id','BIGINT UNSIGNED NULL AFTER value_user_id');
    $add($pageProperties,'value_json','JSON NULL AFTER value_boolean');
    if(!$hasIndex($pageProperties,'idx_page_properties_schema_field'))$pdo->exec("ALTER TABLE `{$pageProperties}` ADD INDEX idx_page_properties_schema_field(schema_field_id), ADD INDEX idx_page_properties_text(property_key(40),value_text(100))");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}feature_flags` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flag_key VARCHAR(100) NOT NULL,
        scope_type ENUM('global','space') NOT NULL DEFAULT 'global',
        space_id BIGINT UNSIGNED NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_feature_flag(flag_key,scope_type,space_id),
        FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(['public_sharing'=>0,'approvals'=>1,'api'=>1,'webhooks'=>1,'personal_spaces'=>0,'plugins'=>0,'oidc'=>0] as $flag=>$enabled){
        $stmt=$pdo->prepare("INSERT IGNORE INTO `{$prefix}feature_flags` (flag_key,scope_type,space_id,enabled,updated_at) VALUES (?,'global',NULL,?,UTC_TIMESTAMP())");$stmt->execute([$flag,$enabled]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}plugins` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        plugin_id VARCHAR(120) NOT NULL UNIQUE,
        name VARCHAR(190) NOT NULL,
        version VARCHAR(50) NOT NULL,
        author VARCHAR(190) NULL,
        required_imwiki VARCHAR(100) NOT NULL,
        permissions_json JSON NOT NULL,
        entrypoint VARCHAR(255) NOT NULL,
        manifest_json JSON NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        compatible TINYINT(1) NOT NULL DEFAULT 0,
        is_core TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}auth_providers` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider_key VARCHAR(80) NOT NULL UNIQUE,
        provider_type ENUM('local','ldap','oidc') NOT NULL,
        display_name VARCHAR(150) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        config_json JSON NOT NULL,
        secret_encrypted TEXT NULL,
        auto_provision TINYINT(1) NOT NULL DEFAULT 0,
        default_role VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO `{$prefix}auth_providers` (provider_key,provider_type,display_name,enabled,config_json,auto_provision,created_at,updated_at) VALUES ('local','local','Local account',1,'{}',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}external_identities` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        provider_key VARCHAR(80) NOT NULL,
        external_id VARCHAR(255) NOT NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_external_identity(provider_key,external_id),
        UNIQUE KEY uq_user_provider(user_id,provider_key),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}trusted_devices` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_prefix VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        device_label VARCHAR(190) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_trusted_devices_user(user_id,revoked_at,expires_at),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}ip_access_rules` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scope ENUM('global','admin') NOT NULL,
        action ENUM('allow','deny') NOT NULL,
        cidr VARCHAR(80) NOT NULL,
        description VARCHAR(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_ip_rule(scope,action,cidr),
        FOREIGN KEY(created_by) REFERENCES `{$prefix}users`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}webhook_deliveries` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        webhook_id BIGINT UNSIGNED NOT NULL,
        event_name VARCHAR(100) NOT NULL,
        payload_json JSON NOT NULL,
        attempt INT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('pending','success','failed','dead') NOT NULL DEFAULT 'pending',
        response_status INT NULL,
        duration_ms INT UNSIGNED NULL,
        last_error VARCHAR(1000) NULL,
        next_attempt_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        delivered_at DATETIME NULL,
        INDEX idx_webhook_delivery(webhook_id,status,next_attempt_at),
        FOREIGN KEY(webhook_id) REFERENCES `{$prefix}webhooks`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $jobs=$prefix.'jobs';
    $add($jobs,'priority','ENUM(\'high\',\'normal\',\'low\') NOT NULL DEFAULT \'normal\' AFTER job_type');
    $add($jobs,'max_attempts','INT UNSIGNED NOT NULL DEFAULT 5 AFTER attempts');
    $add($jobs,'locked_by','VARCHAR(100) NULL AFTER reserved_at');
    $add($jobs,'lock_expires_at','DATETIME NULL AFTER locked_by');
    $add($jobs,'dedupe_key','VARCHAR(190) NULL AFTER payload_json');
    $add($jobs,'error_code','VARCHAR(100) NULL AFTER last_error');
    if(!$hasIndex($jobs,'idx_jobs_priority_ready'))$pdo->exec("ALTER TABLE `{$jobs}` ADD INDEX idx_jobs_priority_ready(status,priority,available_at,id), ADD INDEX idx_jobs_dedupe(dedupe_key,status)");

    $tasks=$prefix.'tasks';
    $pdo->exec("ALTER TABLE `{$tasks}` MODIFY COLUMN status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open'");
    $add($tasks,'priority','ENUM(\'low\',\'normal\',\'high\',\'critical\') NOT NULL DEFAULT \'normal\' AFTER status');
    $add($tasks,'labels_json','JSON NULL AFTER due_date');
    $add($tasks,'due_notified_at','DATETIME NULL AFTER labels_json');
    $add($tasks,'overdue_notified_at','DATETIME NULL AFTER due_notified_at');
    if(!$hasIndex($tasks,'idx_tasks_due_notifications'))$pdo->exec("ALTER TABLE `{$tasks}` ADD INDEX idx_tasks_due_notifications(status,due_date,due_notified_at,overdue_notified_at)");

    $attachments=$prefix.'attachments';
    $add($attachments,'description','VARCHAR(1000) NULL AFTER original_name');
    $add($attachments,'checksum_sha256','CHAR(64) NULL AFTER current_version');
    $add($attachments,'scan_status','ENUM(\'pending\',\'clean\',\'infected\',\'error\',\'not_scanned\') NOT NULL DEFAULT \'not_scanned\' AFTER checksum_sha256');
    $add($attachments,'quarantine_reason','VARCHAR(1000) NULL AFTER scan_status');
    if(!$hasIndex($attachments,'idx_attach_checksum'))$pdo->exec("ALTER TABLE `{$attachments}` ADD INDEX idx_attach_checksum(checksum_sha256), ADD INDEX idx_attach_scan(scan_status,deleted_at)");
    $pdo->exec("UPDATE `{$attachments}` a JOIN `{$prefix}attachment_versions` av ON av.attachment_id=a.id AND av.version_no=a.current_version SET a.checksum_sha256=av.checksum_sha256 WHERE a.checksum_sha256 IS NULL AND av.checksum_sha256 IS NOT NULL");

    $redirects=$prefix.'page_redirects';
    $add($redirects,'hits','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER old_slug');
    $add($redirects,'last_hit_at','DATETIME NULL AFTER hits');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}application_metrics` (
        metric_key VARCHAR(120) NOT NULL,
        metric_date DATE NOT NULL,
        metric_value BIGINT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY(metric_key,metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}search_index_state` (
        engine_key VARCHAR(80) PRIMARY KEY,
        indexed_pages BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_rebuild_at DATETIME NULL,
        cursor_id BIGINT UNSIGNED NULL,
        status ENUM('idle','building','failed') NOT NULL DEFAULT 'idle',
        last_error VARCHAR(1000) NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO `{$prefix}search_index_state` (engine_key,status,updated_at) VALUES ('mysql','idle',UTC_TIMESTAMP())");

    $settings=[
        'personal_spaces.enabled'=>'0','personal_spaces.default_visibility'=>'private','personal_spaces.default_quota_bytes'=>'0',
        'content.classification_required'=>'0','content.default_classification'=>'internal','content.external_links_new_tab'=>'1',
        'security.session_lifetime'=>'28800','security.idle_timeout'=>'3600','security.max_login_attempts'=>'10','security.lockout_seconds'=>'900',
        'security.min_password_length'=>'12','security.password_reset_expiry'=>'3600','security.require_2fa'=>'0','security.allowed_auth_methods'=>'["local"]',
        'security.trusted_device_days'=>'30','security.trusted_proxies'=>'[]','security.hsts'=>'0','security.csp_report_only'=>'0',
        'system.read_only'=>'0','plugins.safe_mode'=>'0','storage.max_file_bytes'=>'10485760','storage.user_quota_bytes'=>'0','storage.space_quota_bytes'=>'0',
        'notifications.quiet_hours'=>'','search.engine'=>'mysql','appearance.accent'=>'','appearance.navigation_width'=>'280','appearance.density'=>'comfortable',
        'homepage.default'=>'dashboard','backup.retention_count'=>'10','backup.retention_days'=>'90','versions.retention_count'=>'0'
    ];
    $stmt=$pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_key=setting_key");
    foreach($settings as $key=>$value)$stmt->execute([$key,$value]);

    $permissions=[
        'admin.access','admin.users','admin.groups','admin.system','admin.security','admin.audit','admin.backup','admin.plugins',
        'users.impersonate','users.impersonate_admin','legal_hold.manage','classification.manage','retention.manage','plugins.manage',
        'authentication.manage','security.manage','audit.export','jobs.manage','storage.manage','content.bulk','content.governance'
    ];
    $permStmt=$pdo->prepare("INSERT IGNORE INTO `{$prefix}permissions` (name,created_at) VALUES (?,UTC_TIMESTAMP())");
    foreach($permissions as $permission)$permStmt->execute([$permission]);
    $pdo->exec("INSERT IGNORE INTO `{$prefix}roles` (name,label,created_at) VALUES ('super_administrator','Super Administrator',UTC_TIMESTAMP())");
    $pdo->exec("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id,permission_id) SELECT r.id,p.id FROM `{$prefix}roles` r CROSS JOIN `{$prefix}permissions` p WHERE r.name='super_administrator'");
};
