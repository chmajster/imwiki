<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $hasColumn=static function(string $table,string $column)use($pdo):bool{$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;};
    if(!$hasColumn($prefix.'pages','sort_order'))$pdo->exec("ALTER TABLE `{$prefix}pages` ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER parent_id, ADD INDEX idx_pages_tree_order(space_id,parent_id,sort_order,id)");
    if(!$hasColumn($prefix.'page_versions','properties_json'))$pdo->exec("ALTER TABLE `{$prefix}page_versions` ADD COLUMN properties_json JSON NULL AFTER content");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}edit_presence` (
        page_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        session_hash CHAR(64) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY(page_id,user_id),
        INDEX idx_presence_updated(updated_at),
        FOREIGN KEY(page_id) REFERENCES `{$prefix}pages`(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
