<?php
declare(strict_types=1);

namespace ImWiki\Repositories;

use ImWiki\Services\SearchQuery;
use PDO;

final class PageRepository
{
    private const MAX_TREE_ROWS = 5000;
    private const MAX_CHILD_ROWS = 250;

    public function __construct(private readonly PDO $pdo, private readonly string $prefix = '') {}

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT p.*, s.name space_name, s.space_key, CONCAT(u.first_name,' ',u.last_name) author_name,ou.username owner_username FROM `{$this->prefix}pages` p JOIN `{$this->prefix}spaces` s ON s.id=p.space_id JOIN `{$this->prefix}users` u ON u.id=p.author_id LEFT JOIN `{$this->prefix}users` ou ON ou.id=p.owner_id WHERE p.id=? AND p.deleted_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(int $spaceId,string $slug): ?array
    {
        $stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}pages` WHERE space_id=? AND slug=? AND deleted_at IS NULL LIMIT 1");$stmt->execute([$spaceId,$slug]);$id=$stmt->fetchColumn();return $id?$this->find((int)$id):null;
    }

    public function redirectedPage(int $spaceId,string $oldSlug): ?array
    {
        $stmt=$this->pdo->prepare("SELECT page_id FROM `{$this->prefix}page_redirects` WHERE space_id=? AND old_slug=? LIMIT 1");$stmt->execute([$spaceId,$oldSlug]);$id=$stmt->fetchColumn();return $id?$this->find((int)$id):null;
    }

    public function tree(int $spaceId,int $limit=self::MAX_TREE_ROWS): array
    {
        $limit=max(1,min(self::MAX_TREE_ROWS,$limit));
        $stmt = $this->pdo->prepare("SELECT id,parent_id,title,slug,sort_order FROM `{$this->prefix}pages` WHERE space_id=? AND deleted_at IS NULL AND status<>'archived' ORDER BY parent_id IS NOT NULL,parent_id,sort_order,title,id LIMIT {$limit}");
        $stmt->execute([$spaceId]);
        return $stmt->fetchAll();
    }

    public function treeVisible(int $spaceId, int $userId, bool $admin,int $limit=self::MAX_TREE_ROWS): array
    {
        $limit=max(1,min(self::MAX_TREE_ROWS,$limit));
        if ($admin) return $this->tree($spaceId,$limit);
        $sql = "SELECT p.id,p.parent_id,p.title,p.slug,p.sort_order FROM `{$this->prefix}pages` p WHERE p.space_id=:space AND p.deleted_at IS NULL AND p.status<>'archived' AND (p.restriction_mode='inherited' OR p.owner_id=:owner OR (p.restriction_mode='specific' AND EXISTS (SELECT 1 FROM `{$this->prefix}page_permissions` pp WHERE pp.page_id=p.id AND pp.can_view=1 AND ((pp.subject_type='user' AND pp.subject_id=:uid) OR (pp.subject_type='group' AND pp.subject_id IN (SELECT gu.group_id FROM `{$this->prefix}group_users` gu WHERE gu.user_id=:guid))))) ORDER BY p.parent_id IS NOT NULL,p.parent_id,p.sort_order,p.title,p.id LIMIT {$limit}";
        $stmt=$this->pdo->prepare($sql);$stmt->execute(['space'=>$spaceId,'owner'=>$userId,'uid'=>$userId,'guid'=>$userId]);return $stmt->fetchAll();
    }

    public function visibleCount(int $spaceId,int $userId,bool $admin):int
    {
        if($admin){$stmt=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE space_id=? AND deleted_at IS NULL AND status<>'archived'");$stmt->execute([$spaceId]);return(int)$stmt->fetchColumn();}
        $sql="SELECT COUNT(*) FROM `{$this->prefix}pages` p WHERE p.space_id=:space AND p.deleted_at IS NULL AND p.status<>'archived' AND (p.restriction_mode='inherited' OR p.owner_id=:owner OR (p.restriction_mode='specific' AND EXISTS (SELECT 1 FROM `{$this->prefix}page_permissions` pp WHERE pp.page_id=p.id AND pp.can_view=1 AND ((pp.subject_type='user' AND pp.subject_id=:uid) OR (pp.subject_type='group' AND pp.subject_id IN (SELECT gu.group_id FROM `{$this->prefix}group_users` gu WHERE gu.user_id=:guid)))))";
        $stmt=$this->pdo->prepare($sql);$stmt->execute(['space'=>$spaceId,'owner'=>$userId,'uid'=>$userId,'guid'=>$userId]);return(int)$stmt->fetchColumn();
    }

    public function children(int $spaceId, ?int $parentId,int $limit=self::MAX_CHILD_ROWS,int $offset=0): array
    {
        $limit=max(1,min(self::MAX_CHILD_ROWS,$limit));$offset=max(0,$offset);
        $parentSql=$parentId===null?'p.parent_id IS NULL':'p.parent_id=:parent';
        $sql="SELECT p.id,p.parent_id,p.title,p.slug,p.sort_order,p.updated_at,EXISTS(SELECT 1 FROM `{$this->prefix}pages` c WHERE c.parent_id=p.id AND c.deleted_at IS NULL AND c.status<>'archived') has_children FROM `{$this->prefix}pages` p WHERE p.space_id=:space AND {$parentSql} AND p.deleted_at IS NULL AND p.status<>'archived' ORDER BY p.sort_order,p.title,p.id LIMIT {$limit} OFFSET {$offset}";
        $stmt=$this->pdo->prepare($sql);$args=['space'=>$spaceId];if($parentId!==null)$args['parent']=$parentId;$stmt->execute($args);return$stmt->fetchAll();
    }

    public function childrenVisible(int $spaceId, ?int $parentId, int $userId, bool $admin,int $limit=self::MAX_CHILD_ROWS,int $offset=0): array
    {
        if($admin)return$this->children($spaceId,$parentId,$limit,$offset);
        $limit=max(1,min(self::MAX_CHILD_ROWS,$limit));$offset=max(0,$offset);$parentSql=$parentId===null?'p.parent_id IS NULL':'p.parent_id=:parent';
        $acl="(p.restriction_mode='inherited' OR p.owner_id=:owner OR (p.restriction_mode='specific' AND EXISTS (SELECT 1 FROM `{$this->prefix}page_permissions` pp WHERE pp.page_id=p.id AND pp.can_view=1 AND ((pp.subject_type='user' AND pp.subject_id=:uid) OR (pp.subject_type='group' AND pp.subject_id IN (SELECT gu.group_id FROM `{$this->prefix}group_users` gu WHERE gu.user_id=:guid)))))";
        $childAcl="(c.restriction_mode='inherited' OR c.owner_id=:child_owner OR (c.restriction_mode='specific' AND EXISTS (SELECT 1 FROM `{$this->prefix}page_permissions` cpp WHERE cpp.page_id=c.id AND cpp.can_view=1 AND ((cpp.subject_type='user' AND cpp.subject_id=:child_uid) OR (cpp.subject_type='group' AND cpp.subject_id IN (SELECT cgu.group_id FROM `{$this->prefix}group_users` cgu WHERE cgu.user_id=:child_guid)))))";
        $sql="SELECT p.id,p.parent_id,p.title,p.slug,p.sort_order,p.updated_at,EXISTS(SELECT 1 FROM `{$this->prefix}pages` c WHERE c.parent_id=p.id AND c.deleted_at IS NULL AND c.status<>'archived' AND {$childAcl}) has_children FROM `{$this->prefix}pages` p WHERE p.space_id=:space AND {$parentSql} AND p.deleted_at IS NULL AND p.status<>'archived' AND {$acl} ORDER BY p.sort_order,p.title,p.id LIMIT {$limit} OFFSET {$offset}";
        $stmt=$this->pdo->prepare($sql);$args=['space'=>$spaceId,'owner'=>$userId,'uid'=>$userId,'guid'=>$userId,'child_owner'=>$userId,'child_uid'=>$userId,'child_guid'=>$userId];if($parentId!==null)$args['parent']=$parentId;$stmt->execute($args);return$stmt->fetchAll();
    }

    public function breadcrumbs(int $pageId): array
    {
        $items=[];$seen=[];$cursor=$pageId;
        while($cursor>0 && count($items)<50){
            if(isset($seen[$cursor]))break;$seen[$cursor]=true;
            $stmt=$this->pdo->prepare("SELECT id,parent_id,title FROM `{$this->prefix}pages` WHERE id=? AND deleted_at IS NULL");$stmt->execute([$cursor]);$row=$stmt->fetch();if(!$row)break;
            $items[]=$row;$cursor=(int)($row['parent_id']??0);
        }
        return array_reverse($items);
    }

    public function recent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("SELECT p.id,p.title,p.updated_at,s.name space_name FROM `{$this->prefix}pages` p JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE p.deleted_at IS NULL ORDER BY p.updated_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function history(int $pageId): array
    {
        $stmt = $this->pdo->prepare("SELECT pv.*, CONCAT(u.first_name,' ',u.last_name) author_name FROM `{$this->prefix}page_versions` pv JOIN `{$this->prefix}users` u ON u.id=pv.author_id WHERE pv.page_id=? ORDER BY pv.version_no DESC LIMIT 100");
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    public function version(int $pageId, int $version): ?array
    {
        $stmt = $this->pdo->prepare("SELECT pv.*,CONCAT(u.first_name,' ',u.last_name) author_name,u.username author_username FROM `{$this->prefix}page_versions` pv JOIN `{$this->prefix}users` u ON u.id=pv.author_id WHERE pv.page_id=? AND pv.version_no=?");
        $stmt->execute([$pageId,$version]);
        return $stmt->fetch() ?: null;
    }

    public function search(string $query, int $limit = 25): array
    {
        return SearchQuery::run($this->pdo,$this->prefix,$query,0,true,$limit);
    }

    public function searchVisible(string $query,int $userId,bool $admin,int $limit=25):array
    {
        return SearchQuery::run($this->pdo,$this->prefix,$query,$userId,$admin,$limit);
    }
}
