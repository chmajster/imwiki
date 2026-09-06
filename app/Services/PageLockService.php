<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Exceptions\AuthorizationException;
use ImWiki\Exceptions\ConflictException;
use ImWiki\Security\Authorization;
use PDO;

final class PageLockService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Authorization $authz){}

    public function active(int $pageId):?array
    {
        $this->pdo->prepare("DELETE FROM `{$this->prefix}page_locks` WHERE page_id=? AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()")->execute([$pageId]);$s=$this->pdo->prepare("SELECT l.*,u.username,CONCAT(u.first_name,' ',u.last_name) owner_name FROM `{$this->prefix}page_locks` l JOIN `{$this->prefix}users` u ON u.id=l.owner_id WHERE l.page_id=? LIMIT 1");$s->execute([$pageId]);return$s->fetch()?:null;
    }

    public function lock(array $page,int $actorId,string $type,string $reason='',?int $minutes=null):array
    {
        if(!$this->authz->canEditPage($actorId,$page)&&!$this->authz->can($actorId,'content.governance'))throw new AuthorizationException();if(!in_array($type,['manual','maintenance','workflow'],true))throw new \InvalidArgumentException('Invalid lock type.');$current=$this->active((int)$page['id']);if($current&&(int)$current['owner_id']!==$actorId)throw new ConflictException('Page is already locked by another user.');$ttl=$minutes??($type==='manual'?120:1440);$ttl=max(5,min(10080,$ttl));$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}page_locks` (page_id,owner_id,lock_type,reason,expires_at,created_at,updated_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id),lock_type=VALUES(lock_type),reason=VALUES(reason),expires_at=VALUES(expires_at),updated_at=UTC_TIMESTAMP()");$stmt->execute([(int)$page['id'],$actorId,$type,mb_substr(trim($reason),0,500),$ttl]);return$this->active((int)$page['id'])??[];
    }

    public function unlock(array $page,int $actorId,bool $force=false):void
    {
        $lock=$this->active((int)$page['id']);if(!$lock)return;$allowed=(int)$lock['owner_id']===$actorId||$this->authz->isAdmin($actorId)||$this->authz->can($actorId,'content.governance');if(!$allowed||($force&&!$this->authz->isAdmin($actorId)&&!$this->authz->can($actorId,'content.governance')))throw new AuthorizationException();$this->pdo->prepare("DELETE FROM `{$this->prefix}page_locks` WHERE page_id=?")->execute([(int)$page['id']]);
    }

    public function assertWritable(array $page,int $actorId):void
    {
        $lock=$this->active((int)$page['id']);if($lock&&(int)$lock['owner_id']!==$actorId&&!$this->authz->isAdmin($actorId))throw new ConflictException('Page is locked: '.((string)$lock['reason']?:'locked for editing').'.');
    }

    public function cleanup():int{return$this->pdo->exec("DELETE FROM `{$this->prefix}page_locks` WHERE expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()");}
}
