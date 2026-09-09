<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use ImWiki\Support\EventDispatcher;
use PDO;
use RuntimeException;

final class PageOperationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly PageRepository $pages,
        private readonly Authorization $authz,
        private readonly PageService $pageService,
        private readonly ?EventDispatcher $events=null,
        private readonly ?SlugService $slugService=null,
    ) {}

    public function move(int $pageId,int $targetSpaceId,?int $parentId,int $userId,int $sortOrder=0):void
    {
        $page=$this->pages->find($pageId);
        if(!$page||!$this->authz->canDeletePage($userId,$page)||!$this->authz->canCreatePage($userId,$targetSpaceId))throw new RuntimeException('Forbidden.');
        $ids=$this->subtreeIds($pageId);
        foreach($ids as $id){$candidate=$this->pages->find($id);if(!$candidate||!$this->authz->canEditPage($userId,$candidate))throw new RuntimeException('Forbidden subtree.');}
        if($parentId!==null){if(in_array($parentId,$ids,true))throw new RuntimeException('Cycle.');$stmt=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE id=? AND space_id=? AND deleted_at IS NULL");$stmt->execute([$parentId,$targetSpaceId]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Invalid target parent.');}

        $this->pdo->beginTransaction();
        try{
            foreach($ids as $id){
                $stmt=$this->pdo->prepare("SELECT slug,title,space_id FROM `{$this->prefix}pages` WHERE id=? FOR UPDATE");$stmt->execute([$id]);$row=$stmt->fetch();
                $this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}page_redirects` (page_id,space_id,old_slug,created_at) VALUES (?,?,?,UTC_TIMESTAMP())")->execute([$id,(int)$row['space_id'],(string)$row['slug']]);
                $slug=$this->slugs()->unique($targetSpaceId,(string)$row['title'],$id);
                $this->pdo->prepare("UPDATE `{$this->prefix}pages` SET space_id=?,slug=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$targetSpaceId,$slug,$id]);
            }
            $this->pdo->prepare("UPDATE `{$this->prefix}pages` SET parent_id=?,sort_order=? WHERE id=?")->execute([$parentId,$sortOrder,$pageId]);
            $this->pdo->prepare("INSERT INTO `{$this->prefix}activity_log` (user_id,action,resource_type,resource_id,description,created_at) VALUES (?,'page.moved','page',?,'Przeniesiono stronę',UTC_TIMESTAMP())")->execute([$userId,$pageId]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function copy(int $pageId,int $targetSpaceId,?int $parentId,int $userId):int
    {
        $page=$this->pages->find($pageId);if(!$page||!$this->authz->canViewPage($userId,$page)||!$this->authz->canCreatePage($userId,$targetSpaceId))throw new RuntimeException('Forbidden.');
        return $this->pageService->create($targetSpaceId,$parentId,'Kopia — '.$page['title'],(string)$page['content'],$userId);
    }

    public function archive(int $pageId,int $userId,bool $archive):void
    {
        $page=$this->pages->find($pageId);if(!$page||!$this->authz->canManagePageRestrictions($userId,$page))throw new RuntimeException('Forbidden.');
        if($archive){
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET pre_archive_status=CASE WHEN status<>'archived' THEN status ELSE pre_archive_status END,status='archived',updated_at=UTC_TIMESTAMP() WHERE id=?");$stmt->execute([$pageId]);
        }else{
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status=COALESCE(pre_archive_status,'published'),pre_archive_status=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='archived'");$stmt->execute([$pageId]);
        }
    }

    public function trash(int $pageId,int $userId):void
    {
        $page=$this->pages->find($pageId);if(!$page||!$this->authz->canDeletePage($userId,$page))throw new RuntimeException('Forbidden.');$ids=$this->subtreeIds($pageId);
        $this->pdo->beginTransaction();
        try{$ph=implode(',',array_fill(0,count($ids),'?'));$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id IN ({$ph})")->execute($ids);$this->pdo->prepare("INSERT INTO `{$this->prefix}activity_log` (user_id,action,resource_type,resource_id,description,created_at) VALUES (?,'page.deleted','page',?,'Przeniesiono stronę do kosza',UTC_TIMESTAMP())")->execute([$userId,$pageId]);$this->events?->dispatch('page.deleted',['actor_id'=>$userId,'page_id'=>$pageId,'space_id'=>(int)$page['space_id'],'title'=>$page['title'],'url'=>'/spaces/'.$page['space_key']]);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function trashList(int $userId):array
    {
        if(!$this->authz->can($userId,'administration.access'))throw new RuntimeException('Forbidden.');
        return $this->pdo->query("SELECT p.id,p.title,p.deleted_at,s.name space_name,s.space_key FROM `{$this->prefix}pages` p JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC LIMIT 200")->fetchAll();
    }

    public function restoreTrash(int $pageId,int $userId):void
    {
        if(!$this->authz->can($userId,'administration.access'))throw new RuntimeException('Forbidden.');
        $this->pdo->beginTransaction();
        try{$ids=$this->deletedSubtreeIds($pageId);if(!$ids)$ids=[$pageId];$ph=implode(',',array_fill(0,count($ids),'?'));$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET deleted_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id IN ({$ph})")->execute($ids);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function purge(int $pageId,int $userId):void
    {
        if(!$this->authz->can($userId,'administration.access'))throw new RuntimeException('Forbidden.');$ids=$this->deletedSubtreeIds($pageId);if(!$ids)throw new RuntimeException('Not in trash.');$ph=implode(',',array_fill(0,count($ids),'?'));$sql="DELETE FROM `{$this->prefix}pages` WHERE deleted_at IS NOT NULL AND id IN ({$ph})";$this->pdo->prepare($sql)->execute($ids);
    }

    private function subtreeIds(int $root):array
    {
        $all=[$root];$queue=[$root];
        while($queue&&count($all)<5000){$id=array_shift($queue);$stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}pages` WHERE parent_id=? AND deleted_at IS NULL");$stmt->execute([$id]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $child){$child=(int)$child;if(!in_array($child,$all,true)){$all[]=$child;$queue[]=$child;}}}
        return $all;
    }

    private function deletedSubtreeIds(int $root):array
    {
        $all=[];$queue=[$root];
        while($queue&&count($all)<5000){$id=array_shift($queue);$stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}pages` WHERE id=? AND deleted_at IS NOT NULL");$stmt->execute([$id]);if(!(int)$stmt->fetchColumn())continue;$all[]=$id;$children=$this->pdo->prepare("SELECT id FROM `{$this->prefix}pages` WHERE parent_id=? AND deleted_at IS NOT NULL");$children->execute([$id]);foreach($children->fetchAll(PDO::FETCH_COLUMN) as $child)$queue[]=(int)$child;}
        return array_values(array_unique($all));
    }

    private function slugs(): SlugService
    {
        return $this->slugService ?? new SlugService($this->pdo, $this->prefix);
    }
}
