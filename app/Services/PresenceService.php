<?php
declare(strict_types=1);
namespace ImWiki\Services;
use ImWiki\Repositories\PageRepository;use ImWiki\Security\Authorization;use PDO;
final class PresenceService{
 public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz){}
 public function heartbeat(int $pageId,int $userId):array{$page=$this->pages->find($pageId);if(!$page||!$this->authz->canEditPage($userId,$page))throw new \RuntimeException('FORBIDDEN');$sessionHash=hash('sha256',session_id());$s=$this->pdo->prepare("INSERT INTO `{$this->prefix}edit_presence` (page_id,user_id,session_hash,updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE session_hash=VALUES(session_hash),updated_at=UTC_TIMESTAMP()");$s->execute([$pageId,$userId,$sessionHash]);$this->pdo->exec("DELETE FROM `{$this->prefix}edit_presence` WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)");$q=$this->pdo->prepare("SELECT ep.user_id,u.username,CONCAT(u.first_name,' ',u.last_name) name FROM `{$this->prefix}edit_presence` ep JOIN `{$this->prefix}users` u ON u.id=ep.user_id WHERE ep.page_id=? AND ep.user_id<>? AND ep.updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 SECOND) ORDER BY ep.updated_at DESC LIMIT 10");$q->execute([$pageId,$userId]);return$q->fetchAll();}
 public function leave(int $pageId,int $userId):void{$this->pdo->prepare("DELETE FROM `{$this->prefix}edit_presence` WHERE page_id=? AND user_id=?")->execute([$pageId,$userId]);}
}
