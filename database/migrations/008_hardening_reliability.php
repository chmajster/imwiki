<?php
declare(strict_types=1);

return static function (PDO $pdo,string $prefix):void {
    $hasColumn=static function(string $table,string $column)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    };
    $hasIndex=static function(string $table,string $index)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table,$index]);
        return (int)$stmt->fetchColumn()>0;
    };

    $pages=$prefix.'pages';
    if(!$hasColumn($pages,'pre_archive_status')){
        $pdo->exec("ALTER TABLE `{$pages}` ADD COLUMN pre_archive_status ENUM('draft','in_review','approved','published') NULL AFTER status");
    }
    $pdo->exec("UPDATE `{$pages}` SET pre_archive_status='published' WHERE status='archived' AND pre_archive_status IS NULL");

    $jobs=$prefix.'jobs';
    if(!$hasIndex($jobs,'idx_jobs_status_available')){
        $pdo->exec("ALTER TABLE `{$jobs}` ADD INDEX idx_jobs_status_available(status,available_at,id)");
    }
    if(!$hasIndex($jobs,'idx_jobs_running_lease')){
        $pdo->exec("ALTER TABLE `{$jobs}` ADD INDEX idx_jobs_running_lease(status,reserved_at)");
    }
};
