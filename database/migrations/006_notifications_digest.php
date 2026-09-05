<?php
declare(strict_types=1);
return static function(PDO $pdo,string $prefix):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}notification_digest_state` (
        user_id BIGINT UNSIGNED NOT NULL,
        frequency ENUM('daily','weekly') NOT NULL,
        last_sent_at DATETIME NOT NULL,
        PRIMARY KEY(user_id,frequency),
        FOREIGN KEY(user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
