<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;
use PDOException;

final class SessionService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function start(int $userId,string $ip,string $userAgent):void
    {
        $raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        try{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}user_sessions` (user_id,session_key_hash,ip_address,user_agent,created_at,last_seen_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$userId,hash('sha256',$raw),substr($ip,0,64),substr($userAgent,0,500)]);$_SESSION['session_key']=$raw;}catch(PDOException){unset($_SESSION['session_key']);}
    }

    public function ensureCurrent(int $userId,string $ip,string $userAgent):bool
    {
        $raw=(string)($_SESSION['session_key']??'');if($raw===''){try{$this->start($userId,$ip,$userAgent);return true;}catch(PDOException){return true;}}
        try{$hash=hash('sha256',$raw);$stmt=$this->pdo->prepare("SELECT id,revoked_at,last_seen_at FROM `{$this->prefix}user_sessions` WHERE user_id=? AND session_key_hash=? LIMIT 1");$stmt->execute([$userId,$hash]);$row=$stmt->fetch();if(!$row||$row['revoked_at'])return false;
            $last=strtotime((string)$row['last_seen_at'])?:0;if(time()-$last>60){$this->pdo->prepare("UPDATE `{$this->prefix}user_sessions` SET last_seen_at=UTC_TIMESTAMP(),ip_address=?,user_agent=? WHERE id=?")->execute([substr($ip,0,64),substr($userAgent,0,500),(int)$row['id']]);}return true;
        }catch(PDOException){return true;}
    }

    public function recordLogin(?int $userId,string $identifier,string $ip,string $userAgent,bool $success):void
    {
        try{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}login_history` (user_id,login_identifier,ip_address,user_agent,success,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())");$stmt->execute([$userId,mb_substr($identifier,0,190),substr($ip,0,64),substr($userAgent,0,500),$success?1:0]);}catch(PDOException){}
    }

    public function listOwn(int $userId):array
    {
        $stmt=$this->pdo->prepare("SELECT id,session_key_hash,ip_address,user_agent,created_at,last_seen_at,revoked_at FROM `{$this->prefix}user_sessions` WHERE user_id=? ORDER BY last_seen_at DESC LIMIT 100");$stmt->execute([$userId]);$current=hash('sha256',(string)($_SESSION['session_key']??''));$rows=$stmt->fetchAll();foreach($rows as &$row)$row['is_current']=hash_equals((string)$row['session_key_hash'],$current);unset($row);return $rows;
    }

    public function history(int $userId,int $limit=50):array
    {
        $stmt=$this->pdo->prepare("SELECT ip_address,user_agent,success,created_at FROM `{$this->prefix}login_history` WHERE user_id=? ORDER BY created_at DESC LIMIT :limit");$stmt->bindValue(1,$userId,PDO::PARAM_INT);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
    }

    public function revokeOwn(int $userId,int $sessionId):bool
    {
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}user_sessions` SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL");$stmt->execute([$sessionId,$userId]);return $stmt->rowCount()>0;
    }

    public function revokeOthers(int $userId):void
    {
        $current=hash('sha256',(string)($_SESSION['session_key']??''));$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}user_sessions` SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND session_key_hash<>? AND revoked_at IS NULL");$stmt->execute([$userId,$current]);
    }

    public function revokeCurrent(int $userId):void
    {
        $raw=(string)($_SESSION['session_key']??'');if($raw==='')return;try{$this->pdo->prepare("UPDATE `{$this->prefix}user_sessions` SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND session_key_hash=? AND revoked_at IS NULL")->execute([$userId,hash('sha256',$raw)]);}catch(PDOException){}
    }

    public function revokeAllForUser(int $userId):void
    {
        $this->pdo->prepare("UPDATE `{$this->prefix}user_sessions` SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);
    }
}
