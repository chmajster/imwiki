<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Html;
use ImWiki\Support\EventDispatcher;
use PDO;

final class PageService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix='',
        private readonly ?MentionService $mentions=null,
        private readonly ?EventDispatcher $events=null,
    ) {}

    public function create(int $spaceId, ?int $parentId, string $title, string $content, int $userId): int
    {
        $title = trim($title);
        if ($title === '') throw new \InvalidArgumentException('Tytuł jest wymagany.');
        if ($parentId !== null && !$this->belongsToSpace($parentId, $spaceId)) throw new \InvalidArgumentException('Nieprawidłowa strona nadrzędna.');
        $slug = $this->uniqueSlug($spaceId, $title);
        $safe = Html::sanitizeRichText($content);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}pages` (space_id,parent_id,title,slug,content,status,restriction_mode,version_no,author_id,last_editor_id,owner_id,created_at,updated_at) VALUES (?,?,?,?,?,'published','inherited',1,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $stmt->execute([$spaceId,$parentId,$title,$slug,$safe,$userId,$userId,$userId]);
            $id = (int)$this->pdo->lastInsertId();
            $ver = $this->pdo->prepare("INSERT INTO `{$this->prefix}page_versions` (page_id,version_no,title,content,properties_json,author_id,change_comment,created_at) VALUES (?,1,?,?,JSON_ARRAY(),?,'Utworzenie strony',UTC_TIMESTAMP())");
            $ver->execute([$id,$title,$safe,$userId]);
            $this->activity($userId,'page.created','page',$id,'Utworzono stronę: '.$title);
            $this->mentions?->process($safe,$userId,$id,'page',$id,'page:'.$id.':v1','/pages/'.$id);
            $this->events?->dispatch('page.created',['actor_id'=>$userId,'page_id'=>$id,'space_id'=>$spaceId,'title'=>$title,'url'=>'/pages/'.$id]);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $pageId, string $title, string $content, int $baseVersion, ?int $parentId, int $userId, string $comment=''): int
    {
        $title=trim($title);if($title==='')throw new \InvalidArgumentException('Tytuł jest wymagany.');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}pages` WHERE id=? AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute([$pageId]);
            $page = $stmt->fetch();
            if (!$page) throw new \RuntimeException('Strona nie istnieje.');
            if ((int)$page['version_no'] !== $baseVersion) throw new \RuntimeException('CONFLICT');
            $this->assertValidParent($pageId,(int)$page['space_id'],$parentId);
            $newVersion = $baseVersion + 1;
            $safe = Html::sanitizeRichText($content);
            $newSlug = $this->slugify($title);
            if ($newSlug !== $page['slug']) {
                $redirect = $this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}page_redirects` (page_id,space_id,old_slug,created_at) VALUES (?,?,?,UTC_TIMESTAMP())");
                $redirect->execute([$pageId,(int)$page['space_id'],$page['slug']]);
                $newSlug = $this->uniqueSlug((int)$page['space_id'],$title,$pageId);
            }
            $upd = $this->pdo->prepare("UPDATE `{$this->prefix}pages` SET parent_id=?,title=?,slug=?,content=?,version_no=?,last_editor_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?");
            $upd->execute([$parentId,$title,$newSlug,$safe,$newVersion,$userId,$pageId]);
            $ver = $this->pdo->prepare("INSERT INTO `{$this->prefix}page_versions` (page_id,version_no,title,content,properties_json,author_id,change_comment,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
            $ver->execute([$pageId,$newVersion,$title,$safe,$this->propertySnapshot($pageId),$userId,trim($comment)]);
            $this->activity($userId,'page.updated','page',$pageId,'Zaktualizowano stronę: '.$title);
            if ((int)($page['parent_id'] ?? 0)!==(int)($parentId ?? 0)) $this->activity($userId,'page.moved','page',$pageId,'Zmieniono stronę nadrzędną: '.$title);
            $this->mentions?->process($safe,$userId,$pageId,'page',$pageId,'page:'.$pageId.':v'.$newVersion,'/pages/'.$pageId);
            $this->events?->dispatch('page.updated',['actor_id'=>$userId,'page_id'=>$pageId,'space_id'=>(int)$page['space_id'],'title'=>$title,'url'=>'/pages/'.$pageId,'version'=>$newVersion]);
            $this->pdo->commit();
            return $newVersion;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function restore(int $pageId, int $version, int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}page_versions` WHERE page_id=? AND version_no=?");
        $stmt->execute([$pageId,$version]);
        $old = $stmt->fetch();
        if (!$old) throw new \RuntimeException('Wersja nie istnieje.');
        $cur = $this->pdo->prepare("SELECT version_no,parent_id FROM `{$this->prefix}pages` WHERE id=? AND deleted_at IS NULL");
        $cur->execute([$pageId]);$current=$cur->fetch();if(!$current)throw new \RuntimeException('Strona nie istnieje.');
        return $this->update($pageId,(string)$old['title'],(string)$old['content'],(int)$current['version_no'],$current['parent_id']!==null?(int)$current['parent_id']:null,$userId,'Przywrócenie wersji '.$version);
    }

    private function belongsToSpace(int $pageId, int $spaceId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE id=? AND space_id=? AND deleted_at IS NULL");
        $stmt->execute([$pageId,$spaceId]);
        return (int)$stmt->fetchColumn()===1;
    }

    private function assertValidParent(int $pageId,int $spaceId,?int $parentId): void
    {
        if($parentId===null)return;
        if($parentId===$pageId)throw new \InvalidArgumentException('Strona nie może być własnym rodzicem.');
        if(!$this->belongsToSpace($parentId,$spaceId))throw new \InvalidArgumentException('Nieprawidłowa strona nadrzędna.');
        $seen=[];$cursor=$parentId;
        while($cursor!==null){
            if($cursor===$pageId)throw new \InvalidArgumentException('Nie można utworzyć cyklu w drzewie stron.');
            if(isset($seen[$cursor]))throw new \RuntimeException('Wykryto uszkodzoną strukturę drzewa.');$seen[$cursor]=true;
            $stmt=$this->pdo->prepare("SELECT parent_id FROM `{$this->prefix}pages` WHERE id=? AND space_id=? AND deleted_at IS NULL");$stmt->execute([$cursor,$spaceId]);$value=$stmt->fetchColumn();
            if($value===false||$value===null)$cursor=null;else$cursor=(int)$value;
        }
    }

    private function uniqueSlug(int $spaceId, string $title, ?int $ignoreId=null): string
    {
        $base = $this->slugify($title);
        $slug = $base; $n = 2;
        while (true) {
            $sql = "SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE space_id=? AND slug=? AND deleted_at IS NULL" . ($ignoreId ? ' AND id<>?' : '');
            $stmt = $this->pdo->prepare($sql);
            $args = [$spaceId,$slug]; if ($ignoreId) $args[]=$ignoreId;
            $stmt->execute($args);
            if ((int)$stmt->fetchColumn()===0) return $slug;
            $slug = $base.'-'.$n++;
        }
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if (function_exists('transliterator_transliterate')) $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/u','-',$value) ?? 'page';
        return trim($value,'-') ?: 'page';
    }

    private function propertySnapshot(int $pageId): string
    {
        $stmt=$this->pdo->prepare("SELECT property_key,label,property_type,value_text,value_number,value_date,value_user_id,value_boolean,options_json FROM `{$this->prefix}page_properties` WHERE page_id=? ORDER BY property_key");
        $stmt->execute([$pageId]);
        return json_encode($stmt->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]';
    }

    private function activity(int $userId,string $action,string $type,int $id,string $description): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}activity_log` (user_id,action,resource_type,resource_id,description,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
        $stmt->execute([$userId,$action,$type,$id,$description]);
    }
}
