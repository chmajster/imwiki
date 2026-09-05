<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class WorkflowService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz,private readonly NotificationService $notifications){}

    public function enabled():bool
    {
        $stmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='workflow.status_enabled'");$stmt->execute();return (string)($stmt->fetchColumn()?:'0')==='1';
    }

    public function setEnabled(bool $enabled):void
    {
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES ('workflow.status_enabled',?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");$stmt->execute([$enabled?'1':'0']);
    }

    public function history(int $pageId):array
    {
        $stmt=$this->pdo->prepare("SELECT a.*,r.username reviewer_username,CONCAT(r.first_name,' ',r.last_name) reviewer_name,q.username requester_username,CONCAT(q.first_name,' ',q.last_name) requester_name FROM `{$this->prefix}approval_history` a LEFT JOIN `{$this->prefix}users` r ON r.id=a.reviewer_id JOIN `{$this->prefix}users` q ON q.id=a.requested_by WHERE a.page_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 100");$stmt->execute([$pageId]);return $stmt->fetchAll();
    }

    public function requestReview(int $pageId,string $reviewerUsername,int $actorId):void
    {
        if(!$this->enabled())throw new \RuntimeException('WORKFLOW_DISABLED');$page=$this->requireEditable($pageId,$actorId);$reviewerUsername=trim($reviewerUsername);if($reviewerUsername==='')throw new \InvalidArgumentException('Wskaż reviewera.');
        $find=$this->pdo->prepare("SELECT id FROM `{$this->prefix}users` WHERE username=? AND status='active' AND deleted_at IS NULL");$find->execute([$reviewerUsername]);$reviewerId=(int)($find->fetchColumn()?:0);if($reviewerId<=0)throw new \InvalidArgumentException('Nie znaleziono aktywnego reviewera.');
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare("UPDATE `{$this->prefix}approval_history` SET decision='cancelled',decided_at=UTC_TIMESTAMP() WHERE page_id=? AND decision='pending'")->execute([$pageId]);
            $this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status='in_review',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$pageId]);
            $ins=$this->pdo->prepare("INSERT INTO `{$this->prefix}approval_history` (page_id,page_version,reviewer_id,requested_by,decision,created_at) VALUES (?,?,?,?,'pending',UTC_TIMESTAMP())");$ins->execute([$pageId,(int)$page['version_no'],$reviewerId,$actorId]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        if($reviewerId!==$actorId)$this->notifications->create($reviewerId,'approval.requested',$actorId,'page',$pageId,'/pages/'.$pageId.'#workflow',['title'=>$page['title'],'version'=>(int)$page['version_no']]);
    }

    public function decide(int $pageId,string $decision,string $comment,int $actorId):void
    {
        if(!$this->enabled())throw new \RuntimeException('WORKFLOW_DISABLED');if(!in_array($decision,['approved','rejected'],true))throw new \InvalidArgumentException('Nieprawidłowa decyzja.');$comment=trim($comment);if(mb_strlen($comment)>1000)throw new \InvalidArgumentException('Komentarz jest za długi.');
        $this->pdo->beginTransaction();try{
            $stmt=$this->pdo->prepare("SELECT a.*,p.title,p.space_id,p.owner_id,p.author_id FROM `{$this->prefix}approval_history` a JOIN `{$this->prefix}pages` p ON p.id=a.page_id WHERE a.page_id=? AND a.decision='pending' ORDER BY a.id DESC LIMIT 1 FOR UPDATE");$stmt->execute([$pageId]);$approval=$stmt->fetch();if(!$approval)throw new \RuntimeException('NO_PENDING_REVIEW');
            if((int)$approval['reviewer_id']!==$actorId&&!$this->authz->isAdmin($actorId))throw new \RuntimeException('FORBIDDEN');
            $this->pdo->prepare("UPDATE `{$this->prefix}approval_history` SET decision=?,comment=?,decided_at=UTC_TIMESTAMP() WHERE id=?")->execute([$decision,$comment?:null,(int)$approval['id']]);
            $status=$decision==='approved'?'approved':'draft';$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$pageId]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $requester=(int)$approval['requested_by'];if($requester!==$actorId)$this->notifications->create($requester,'approval.'.$decision,$actorId,'page',$pageId,'/pages/'.$pageId.'#workflow',['title'=>$approval['title'],'comment'=>$comment]);
    }

    public function publish(int $pageId,int $actorId):void
    {
        if(!$this->enabled())throw new \RuntimeException('WORKFLOW_DISABLED');$page=$this->requireEditable($pageId,$actorId);if($page['status']!=='approved')throw new \RuntimeException('NOT_APPROVED');$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status='published',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$pageId]);
    }

    public function setDraft(int $pageId,int $actorId):void
    {
        if(!$this->enabled())throw new \RuntimeException('WORKFLOW_DISABLED');$this->requireEditable($pageId,$actorId);$this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE `{$this->prefix}approval_history` SET decision='cancelled',decided_at=UTC_TIMESTAMP() WHERE page_id=? AND decision='pending'")->execute([$pageId]);$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status='draft',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$pageId]);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function canDecide(int $pageId,int $userId):bool
    {
        if($this->authz->isAdmin($userId))return true;$stmt=$this->pdo->prepare("SELECT reviewer_id FROM `{$this->prefix}approval_history` WHERE page_id=? AND decision='pending' ORDER BY id DESC LIMIT 1");$stmt->execute([$pageId]);return (int)($stmt->fetchColumn()?:0)===$userId;
    }

    private function requireEditable(int $pageId,int $actorId):array
    {
        $page=$this->pages->find($pageId);if(!$page||!$this->authz->canEditPage($actorId,$page))throw new \RuntimeException('FORBIDDEN');return $page;
    }
}
