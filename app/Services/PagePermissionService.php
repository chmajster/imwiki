<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class PagePermissionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly PageRepository $pages,
        private readonly Authorization $authz,
        private readonly NotificationService $notifications,
    ) {}

    public function grants(int $pageId): array
    {
        $stmt=$this->pdo->prepare("SELECT pp.*,CASE WHEN pp.subject_type='user' THEN u.username ELSE g.name END subject_key,CASE WHEN pp.subject_type='user' THEN CONCAT(u.first_name,' ',u.last_name) ELSE g.label END subject_label FROM `{$this->prefix}page_permissions` pp LEFT JOIN `{$this->prefix}users` u ON pp.subject_type='user' AND u.id=pp.subject_id LEFT JOIN `{$this->prefix}groups` g ON pp.subject_type='group' AND g.id=pp.subject_id WHERE pp.page_id=? ORDER BY pp.subject_type,subject_label");
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    public function setMode(int $pageId, string $mode, int $actorId): void
    {
        if(!in_array($mode,['inherited','specific','private'],true)) throw new \InvalidArgumentException('Nieprawidłowy tryb ograniczeń.');
        $page=$this->requireManageable($pageId,$actorId);
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET restriction_mode=?,updated_at=UTC_TIMESTAMP() WHERE id=?");
            $stmt->execute([$mode,$pageId]);
            if($mode!=='specific'){
                $del=$this->pdo->prepare("DELETE FROM `{$this->prefix}page_permissions` WHERE page_id=?");
                $del->execute([$pageId]);
            }
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function grant(int $pageId, string $subjectType, int $subjectId, bool $canEdit, int $actorId): void
    {
        if(!in_array($subjectType,['user','group'],true) || $subjectId<=0) throw new \InvalidArgumentException('Nieprawidłowy odbiorca uprawnień.');
        $page=$this->requireManageable($pageId,$actorId);
        if(!$this->subjectExists($subjectType,$subjectId)) throw new \InvalidArgumentException('Użytkownik lub grupa nie istnieje.');

        $this->pdo->beginTransaction();
        try{
            $mode=$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET restriction_mode='specific',updated_at=UTC_TIMESTAMP() WHERE id=?");
            $mode->execute([$pageId]);
            $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}page_permissions` (page_id,subject_type,subject_id,can_view,can_edit,can_delete,can_comment) VALUES (?,?,?,1,?,0,1) ON DUPLICATE KEY UPDATE can_view=1,can_edit=VALUES(can_edit),can_comment=1");
            $stmt->execute([$pageId,$subjectType,$subjectId,$canEdit?1:0]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}

        if($subjectType==='user' && $subjectId!==$actorId){
            $this->notifications->create($subjectId,'page.shared',$actorId,'page',$pageId,'/pages/'.$pageId,['access'=>$canEdit?'edit':'view','title'=>$page['title']]);
        }
    }

    public function revoke(int $pageId, int $grantId, int $actorId): void
    {
        $this->requireManageable($pageId,$actorId);
        $stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}page_permissions` WHERE id=? AND page_id=?");
        $stmt->execute([$grantId,$pageId]);
    }

    public function users(string $query=''): array
    {
        $query=trim($query);
        if($query===''){
            $stmt=$this->pdo->query("SELECT id,username,first_name,last_name FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL ORDER BY username LIMIT 100");
        }else{
            $stmt=$this->pdo->prepare("SELECT id,username,first_name,last_name FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY username LIMIT 100");
            $like='%'.$query.'%';$stmt->execute([$like,$like,$like]);
        }
        return $stmt->fetchAll();
    }

    public function groups(): array
    {
        return $this->pdo->query("SELECT id,name,label FROM `{$this->prefix}groups` ORDER BY label LIMIT 100")->fetchAll();
    }

    private function requireManageable(int $pageId,int $actorId): array
    {
        $page=$this->pages->find($pageId);
        if(!$page || !$this->authz->canManagePageRestrictions($actorId,$page)) throw new \RuntimeException('FORBIDDEN');
        return $page;
    }

    private function subjectExists(string $type,int $id): bool
    {
        $table=$type==='user'?'users':'groups';
        $extra=$type==='user'?" AND deleted_at IS NULL":'';
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}{$table}` WHERE id=?{$extra}");
        $stmt->execute([$id]);return (int)$stmt->fetchColumn()===1;
    }
}
