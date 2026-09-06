<?php
declare(strict_types=1);

return static function(PDO $pdo,string $prefix):void{
    $hasColumn=static function(string $table,string $column)use($pdo):bool{
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    };

    $groups=$prefix.'groups';
    if(!$hasColumn($groups,'system')){
        $pdo->exec("ALTER TABLE `{$groups}` ADD COLUMN `system` TINYINT(1) NOT NULL DEFAULT 0 AFTER label");
    }
    if(!$hasColumn($groups,'external_source')){
        $pdo->exec("ALTER TABLE `{$groups}` ADD COLUMN external_source VARCHAR(80) NULL AFTER `system`");
    }
};
