<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class RetentionService
{
    private const DEFAULTS=['application_logs'=>30,'audit_logs'=>365,'login_history'=>365,'notifications'=>180,'trash'=>0];
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly string $root){}

    public function settings(): array
    {
        $out=self::DEFAULTS;
        $stmt=$this->pdo->query("SELECT setting_key,setting_value FROM `{$this->prefix}settings` WHERE setting_key LIKE 'retention.%'");
        foreach($stmt->fetchAll() as $r){$k=substr((string)$r['setting_key'],10);if(array_key_exists($k,$out))$out[$k]=max(0,min(3650,(int)$r['setting_value']));}
        return $out;
    }

    public function save(array $values): void
    {
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");
        foreach(self::DEFAULTS as $k=>$default){$v=max(0,min(3650,(int)($values[$k]??$default)));$stmt->execute(['retention.'.$k,(string)$v]);}
    }

    public function cleanup(): array
    {
        $s=$this->settings();$result=[];
        $result['audit_logs']=$this->deleteOlder('audit_log','created_at',$s['audit_logs']);
        $result['login_history']=$this->deleteOlder('login_history','created_at',$s['login_history']);
        $result['notifications']=$this->deleteOlder('notifications','created_at',$s['notifications']);
        $result['trash']=$s['trash']>0?$this->purgeTrash($s['trash']):0;
        $result['application_logs']=$this->rotateLog($s['application_logs']);
        return $result;
    }

    private function deleteOlder(string $table,string $column,int $days): int
    {
        if($days<=0)return 0;
        $stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}{$table}` WHERE `{$column}` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)");
        $stmt->bindValue(1,$days,PDO::PARAM_INT);$stmt->execute();return $stmt->rowCount();
    }

    private function purgeTrash(int $days): int
    {
        $stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}pages` WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)");
        $stmt->bindValue(1,$days,PDO::PARAM_INT);$stmt->execute();return $stmt->rowCount();
    }

    private function rotateLog(int $days): int
    {
        if($days<=0)return 0;$path=$this->root.'/storage/logs/imwiki.log';if(!is_file($path))return 0;
        $mtime=@filemtime($path);if($mtime!==false && $mtime<time()-($days*86400)){@file_put_contents($path,'',LOCK_EX);return 1;}return 0;
    }
}
