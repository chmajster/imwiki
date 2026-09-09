<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;
use RuntimeException;
use Throwable;

final class BackupArtifactService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly string $root,
        private readonly BackupService $backups,
        private readonly JobQueueService $jobs,
    ) {}

    public function request(int $userId): int
    {
        $this->expireOld();
        $check=$this->pdo->prepare("SELECT id FROM `{$this->prefix}backup_artifacts` WHERE requested_by=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        $check->execute([$userId]);
        $existing=(int)($check->fetchColumn()?:0);
        if($existing>0)return $existing;

        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}backup_artifacts` (requested_by,status,created_at,expires_at) VALUES (?,'queued',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
            $stmt->execute([$userId]);
            $id=(int)$this->pdo->lastInsertId();
            $this->jobs->enqueue('backup',['artifact_id'=>$id]);
            $this->pdo->commit();
            return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function process(int $artifactId): void
    {
        $claim=$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='running',started_at=COALESCE(started_at,UTC_TIMESTAMP()),error_message=NULL WHERE id=? AND status IN ('queued','failed')");
        $claim->execute([$artifactId]);
        if($claim->rowCount()!==1){
            $status=$this->status($artifactId);
            if($status==='ready'||$status==='expired')return;
            throw new RuntimeException('BACKUP_ARTIFACT_NOT_CLAIMABLE');
        }

        $temporary=null;$target=null;
        try{
            $temporary=$this->backups->create();
            $dir=$this->root.'/storage/private/backups';
            if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('BACKUP_ARTIFACT_DIR');
            $stored=bin2hex(random_bytes(24)).'.zip';$target=$dir.'/'.$stored;
            if(!@rename($temporary,$target)){
                if(!@copy($temporary,$target))throw new RuntimeException('BACKUP_ARTIFACT_MOVE');
                @unlink($temporary);
            }
            @chmod($target,0640);
            $size=filesize($target);$hash=hash_file('sha256',$target);
            if($size===false||$hash===false)throw new RuntimeException('BACKUP_ARTIFACT_META');
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='ready',stored_name=?,size_bytes=?,checksum_sha256=?,finished_at=UTC_TIMESTAMP(),error_message=NULL WHERE id=?");
            $stmt->execute([$stored,(int)$size,$hash,$artifactId]);
        }catch(Throwable $e){
            if($temporary&&is_file($temporary))@unlink($temporary);
            if($target&&is_file($target))@unlink($target);
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='failed',finished_at=UTC_TIMESTAMP(),error_message=? WHERE id=?");
            $stmt->execute([mb_substr($e->getMessage(),0,1000),$artifactId]);
        }
    }

    public function listRecent(int $limit=50):array
    {
        $this->expireOld();$limit=max(1,min(100,$limit));
        return $this->pdo->query("SELECT b.id,b.requested_by,b.status,b.size_bytes,b.checksum_sha256,b.error_message,b.created_at,b.started_at,b.finished_at,b.expires_at,u.username FROM `{$this->prefix}backup_artifacts` b JOIN `{$this->prefix}users` u ON u.id=b.requested_by ORDER BY b.id DESC LIMIT {$limit}")->fetchAll();
    }

    public function resolveReady(int $id):?array
    {
        $this->expireOld();
        $stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}backup_artifacts` WHERE id=? AND status='ready' AND expires_at>UTC_TIMESTAMP() LIMIT 1");$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)return null;
        $name=(string)($row['stored_name']??'');if(!preg_match('/^[a-f0-9]{48}\.zip$/',$name))return null;$path=$this->root.'/storage/private/backups/'.$name;if(!is_file($path))return null;
        $hash=hash_file('sha256',$path);if($hash===false||!hash_equals((string)$row['checksum_sha256'],$hash))throw new RuntimeException('BACKUP_ARTIFACT_CHECKSUM');
        $row['path']=$path;return$row;
    }

    public function retry(int $id,int $userId):void
    {
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='queued',error_message=NULL,started_at=NULL,finished_at=NULL,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) WHERE id=? AND requested_by=? AND status='failed'");$stmt->execute([$id,$userId]);
        if($stmt->rowCount()===1)$this->jobs->enqueue('backup',['artifact_id'=>$id]);
    }

    public function expireOld():int
    {
        $stmt=$this->pdo->query("SELECT id,stored_name FROM `{$this->prefix}backup_artifacts` WHERE status='ready' AND expires_at<=UTC_TIMESTAMP() LIMIT 500");$rows=$stmt->fetchAll();$count=0;
        foreach($rows as $row){$name=(string)($row['stored_name']??'');if(preg_match('/^[a-f0-9]{48}\.zip$/',$name))@unlink($this->root.'/storage/private/backups/'.$name);$this->pdo->prepare("UPDATE `{$this->prefix}backup_artifacts` SET status='expired',stored_name=NULL WHERE id=?")->execute([(int)$row['id']]);$count++;}
        return$count;
    }

    private function status(int $id):string
    {
        $stmt=$this->pdo->prepare("SELECT status FROM `{$this->prefix}backup_artifacts` WHERE id=?");$stmt->execute([$id]);return(string)($stmt->fetchColumn()?:'');
    }
}
