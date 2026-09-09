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
        $result['orphan_uploads']=$this->garbageCollectUploads();
        $result['backup_artifacts']=$this->expireBackupArtifacts();
        $result['application_logs']=$this->rotateLogs($s['application_logs']);
        return $result;
    }

    public function garbageCollectUploads(bool $dryRun=false): int
    {
        $dir=$this->root.'/storage/uploads';if(!is_dir($dir))return 0;
        $referenced=[];
        $stmt=$this->pdo->query("SELECT stored_name FROM `{$this->prefix}attachment_versions`");
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $name)$referenced[(string)$name]=true;
        $count=0;
        foreach(new \DirectoryIterator($dir) as $file){
            if(!$file->isFile())continue;$name=$file->getFilename();
            if(!preg_match('/^[a-f0-9]{48}$/',$name)||isset($referenced[$name]))continue;
            if($dryRun){$count++;continue;}
            if(@unlink($file->getPathname()))$count++;
        }
        return $count;
    }

    private function expireBackupArtifacts(): int
    {
        $stmt=$this->pdo->query("SELECT id,status,stored_name FROM `{$this->prefix}backup_artifacts` WHERE status IN ('ready','failed') AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP() LIMIT 500");
        $rows=$stmt->fetchAll();$count=0;
        $mark=$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='expired',stored_name=NULL WHERE id=? AND status IN ('ready','failed')");
        foreach($rows as $row){
            $name=(string)($row['stored_name']??'');
            if($name!==''&&preg_match('/^[a-f0-9]{48}\.zip$/',$name))@unlink($this->root.'/storage/private/backups/'.$name);
            $mark->execute([(int)$row['id']]);$count+=$mark->rowCount();
        }
        return $count;
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

    private function rotateLogs(int $days): int
    {
        if($days<=0)return 0;$dir=$this->root.'/storage/logs';if(!is_dir($dir))return 0;
        $cutoff=time()-($days*86400);$count=0;
        foreach(glob($dir.'/imwiki*.log')?:[] as $path){
            $mtime=@filemtime($path);if($mtime!==false&&$mtime<$cutoff&&@unlink($path))$count++;
        }
        return $count;
    }
}
