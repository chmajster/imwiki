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

    $jobs=$prefix.'jobs';
    if(!$hasColumn($jobs,'updated_at')){
        $pdo->exec("ALTER TABLE `{$jobs}` ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        $pdo->exec("UPDATE `{$jobs}` SET updated_at=COALESCE(finished_at,reserved_at,created_at) WHERE updated_at IS NULL");
        $pdo->exec("ALTER TABLE `{$jobs}` MODIFY COLUMN updated_at DATETIME NOT NULL");
    }
    if(!$hasIndex($jobs,'idx_jobs_updated')){
        $pdo->exec("ALTER TABLE `{$jobs}` ADD INDEX idx_jobs_updated(status,updated_at)");
    }

    $attachments=$prefix.'attachments';
    if(!$hasColumn($attachments,'quarantined_at')){
        $pdo->exec("ALTER TABLE `{$attachments}` ADD COLUMN quarantined_at DATETIME NULL AFTER quarantine_reason");
        $pdo->exec("UPDATE `{$attachments}` SET quarantined_at=created_at WHERE scan_status='infected' AND quarantined_at IS NULL");
    }

    $webhookDeliveries=$prefix.'webhook_deliveries';
    if($hasColumn($webhookDeliveries,'response_body')&&!$hasColumn($webhookDeliveries,'response_body_hash')){
        $pdo->exec("ALTER TABLE `{$webhookDeliveries}` ADD COLUMN response_body_hash CHAR(64) NULL AFTER response_body");
    }

    $settings=[
        'release.schema_version'=>'010_enterprise_runtime_alignment.php',
        'release.minimum_php'=>'8.2.0',
        'release.supported_from'=>'0.2.0',
        'health.detailed_admin_only'=>'1'
    ];
    $stmt=$pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");
    foreach($settings as $key=>$value)$stmt->execute([$key,$value]);
};
