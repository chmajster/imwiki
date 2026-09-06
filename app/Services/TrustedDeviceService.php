<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class TrustedDeviceService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}
    public function issue(int $userId,string $label,string $ip,string $userAgent,int $days):string{$days=max(1,min(365,$days));$raw='imwd_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$hash=hash('sha256',$raw);$prefix=substr($raw,0,13);$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}trusted_devices` (user_id,token_prefix,token_hash,device_label,ip_address,user_agent,expires_at,created_at) VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY),UTC_TIMESTAMP())");$stmt->execute([$userId,$prefix,$hash,mb_substr(trim($label),0,190),mb_substr($ip,0,64),mb_substr($userAgent,0,500),$days]);return$raw;}
    public function validate(int $userId,string $raw):bool{if(!str_starts_with($raw,'imwd_')||strlen($raw)<30)return false;$hash=hash('sha256',$raw);$stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}trusted_devices` WHERE user_id=? AND token_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1");$stmt->execute([$userId,$hash]);$id=(int)($stmt->fetchColumn()?:0);if($id<=0)return false;$this->pdo->prepare("UPDATE `{$this->prefix}trusted_devices` SET last_used_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);return true;}
    public function all(int $userId):array{$stmt=$this->pdo->prepare("SELECT id,token_prefix,device_label,ip_address,user_agent,expires_at,last_used_at,revoked_at,created_at FROM `{$this->prefix}trusted_devices` WHERE user_id=? ORDER BY created_at DESC LIMIT 100");$stmt->execute([$userId]);return$stmt->fetchAll()?:[];}
    public function revoke(int $userId,int $id):void{$this->pdo->prepare("UPDATE `{$this->prefix}trusted_devices` SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?")->execute([$id,$userId]);}
    public function revokeAll(int $userId):void{$this->pdo->prepare("UPDATE `{$this->prefix}trusted_devices` SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);}
    public function cleanup():int{return$this->pdo->exec("DELETE FROM `{$this->prefix}trusted_devices` WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) OR revoked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)");}
}
