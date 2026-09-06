<?php
declare(strict_types=1);

namespace ImWiki\Storage;

use ImWiki\Exceptions\StorageException;
use PDO;

final class StorageManager
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly string $root){}

    public function assertUploadAllowed(int $userId,int $spaceId,int $bytes):void
    {
        $global=max(1,(int)$this->setting('storage.max_file_bytes',(string)(10*1024*1024)));if($bytes<=0||$bytes>$global)throw new StorageException('Upload exceeds the global file size limit.');$userQuota=max(0,(int)$this->setting('storage.user_quota_bytes','0'));if($userQuota>0&&$this->userUsage($userId)+$bytes>$userQuota)throw new StorageException('User storage quota exceeded.');$spaceQuota=$this->spaceQuota($spaceId);if($spaceQuota>0&&$this->spaceUsage($spaceId)+$bytes>$spaceQuota)throw new StorageException('Space storage quota exceeded.');$uploads=$this->root.'/storage/uploads';if(!is_dir($uploads)&&!@mkdir($uploads,0770,true))throw new StorageException('Upload storage is unavailable.');if(!is_writable($uploads))throw new StorageException('Upload storage is not writable.');$free=@disk_free_space($uploads);if($free!==false&&$free<$bytes+1048576)throw new StorageException('Insufficient disk space.');
    }

    public function dashboard():array
    {
        $upload=$this->directoryBytes($this->root.'/storage/uploads');$avatars=$this->directoryBytes($this->root.'/storage/avatars');$backups=$this->directoryBytes($this->root.'/storage/backups');$logs=$this->directoryBytes($this->root.'/storage/logs');$cache=$this->directoryBytes($this->root.'/storage/cache');$private=$this->directoryBytes($this->root.'/storage/private');$root=$this->root.'/storage';$total=$upload+$avatars+$backups+$logs+$cache+$private;$free=is_dir($root)?@disk_free_space($root):false;$capacity=is_dir($root)?@disk_total_space($root):false;return['total_bytes'=>$total,'attachments_bytes'=>$upload,'avatars_bytes'=>$avatars,'backups_bytes'=>$backups,'logs_bytes'=>$logs,'cache_bytes'=>$cache,'private_bytes'=>$private,'disk_free_bytes'=>$free===false?null:(int)$free,'disk_total_bytes'=>$capacity===false?null:(int)$capacity,'db_attachment_bytes'=>(int)$this->pdo->query("SELECT COALESCE(SUM(size_bytes),0) FROM `{$this->prefix}attachments` WHERE deleted_at IS NULL")->fetchColumn(),'quarantined'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}attachments` WHERE scan_status='infected' AND deleted_at IS NULL")->fetchColumn()];
    }

    public function cleanupCandidates():array
    {
        $referenced=[];foreach($this->pdo->query("SELECT stored_name FROM `{$this->prefix}attachment_versions`")->fetchAll(PDO::FETCH_COLUMN) as $n)$referenced[(string)$n]=true;$orphans=[];$dir=$this->root.'/storage/uploads';if(is_dir($dir))foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..'||!is_file($dir.'/'.$name))continue;if(!isset($referenced[$name]))$orphans[]=['name'=>$name,'size'=>(int)filesize($dir.'/'.$name),'mtime'=>(int)filemtime($dir.'/'.$name)];}$unused=$this->pdo->query("SELECT a.id,a.original_name,a.size_bytes,a.deleted_at FROM `{$this->prefix}attachments` a WHERE a.deleted_at IS NOT NULL ORDER BY a.deleted_at LIMIT 500")->fetchAll();$backupDays=max(1,(int)$this->setting('backup.retention_days','90'));$expiredBackups=$this->pdo->query("SELECT id,filename,size_bytes,created_at FROM `{$this->prefix}backup_records` WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$backupDays} DAY) ORDER BY created_at")->fetchAll();return['orphan_files'=>$orphans,'deleted_attachment_records'=>$unused?:[],'expired_backups'=>$expiredBackups?:[]];
    }

    public function cleanupOrphanFile(string $name):bool
    {
        if(!preg_match('/^[a-f0-9]{48}$/',$name))return false;$s=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}attachment_versions` WHERE stored_name=?");$s->execute([$name]);if((int)$s->fetchColumn()>0)return false;$path=$this->root.'/storage/uploads/'.$name;return is_file($path)&&@unlink($path);
    }

    public function verifyAttachments(int $limit=500):array
    {
        $stmt=$this->pdo->query("SELECT v.id,v.stored_name,v.checksum_sha256,a.original_name FROM `{$this->prefix}attachment_versions` v JOIN `{$this->prefix}attachments` a ON a.id=v.attachment_id ORDER BY v.id DESC LIMIT ".max(1,min(5000,$limit)));$ok=[];$missing=[];$mismatch=[];foreach($stmt->fetchAll() as $row){$path=$this->root.'/storage/uploads/'.$row['stored_name'];if(!is_file($path)){$missing[]=(int)$row['id'];continue;}$actual=hash_file('sha256',$path);if($row['checksum_sha256']&&$actual!==$row['checksum_sha256'])$mismatch[]=(int)$row['id'];else$ok[]=(int)$row['id'];}return['ok'=>count($ok),'missing'=>$missing,'checksum_mismatch'=>$mismatch];
    }

    public function userUsage(int $uid):int{$s=$this->pdo->prepare("SELECT COALESCE(SUM(size_bytes),0) FROM `{$this->prefix}attachments` WHERE uploader_id=? AND deleted_at IS NULL");$s->execute([$uid]);return(int)$s->fetchColumn();}
    public function spaceUsage(int $spaceId):int{$s=$this->pdo->prepare("SELECT COALESCE(SUM(a.size_bytes),0) FROM `{$this->prefix}attachments` a JOIN `{$this->prefix}pages` p ON p.id=a.page_id WHERE p.space_id=? AND p.deleted_at IS NULL AND a.deleted_at IS NULL");$s->execute([$spaceId]);return(int)$s->fetchColumn();}
    private function spaceQuota(int $spaceId):int{$s=$this->pdo->prepare("SELECT storage_quota_bytes FROM `{$this->prefix}spaces` WHERE id=?");$s->execute([$spaceId]);$specific=(int)($s->fetchColumn()?:0);return$specific>0?$specific:max(0,(int)$this->setting('storage.space_quota_bytes','0'));}
    private function setting(string $key,string $default):string{$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
    private function directoryBytes(string $dir):int{if(!is_dir($dir))return 0;$sum=0;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));foreach($it as $file){if($file->isFile()&&!$file->isLink())$sum+=$file->getSize();}return$sum;}
}
