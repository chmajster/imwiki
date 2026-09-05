<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $hasColumn=static function(string $table,string $column)use($pdo):bool{$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;};
    if(!$hasColumn($prefix.'comments','thread_status'))$pdo->exec("ALTER TABLE `{$prefix}comments` ADD COLUMN thread_status ENUM('open','resolved') NOT NULL DEFAULT 'open' AFTER body");
    if(!$hasColumn($prefix.'spaces','sidebar_config_json'))$pdo->exec("ALTER TABLE `{$prefix}spaces` ADD COLUMN sidebar_config_json JSON NULL AFTER homepage_page_id");
    if(!$hasColumn($prefix.'templates','space_id')){$pdo->exec("ALTER TABLE `{$prefix}templates` ADD COLUMN space_id BIGINT UNSIGNED NULL AFTER id, ADD COLUMN labels_json JSON NULL AFTER content, ADD COLUMN properties_json JSON NULL AFTER labels_json, ADD COLUMN archived_at DATETIME NULL AFTER is_system");$pdo->exec("ALTER TABLE `{$prefix}templates` ADD CONSTRAINT fk_templates_space FOREIGN KEY(space_id) REFERENCES `{$prefix}spaces`(id) ON DELETE CASCADE");}
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}user_preferences` (user_id BIGINT UNSIGNED PRIMARY KEY,dashboard_json JSON NULL,notification_json JSON NULL,updated_at DATETIME NOT NULL,FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
