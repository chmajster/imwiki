<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;
use RuntimeException;

final class InteractionService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz){}
    public function togglePageReaction(int $pageId,int $userId,string $reaction):void
    {
        if(!in_array($reaction,['helpful','like'],true))throw new RuntimeException('Invalid reaction.');$page=$this->pages->find($pageId);if(!$page||!$this->authz->canViewPage($userId,$page))throw new RuntimeException('Forbidden.');
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}page_reactions` WHERE page_id=? AND user_id=? AND reaction=?");$stmt->execute([$pageId,$userId,$reaction]);if($stmt->fetchColumn())$this->pdo->prepare("DELETE FROM `{$this->prefix}page_reactions` WHERE page_id=? AND user_id=? AND reaction=?")->execute([$pageId,$userId,$reaction]);else$this->pdo->prepare("INSERT INTO `{$this->prefix}page_reactions` (page_id,user_id,reaction,created_at) VALUES (?,?,?,UTC_TIMESTAMP())")->execute([$pageId,$userId,$reaction]);
    }
    public function pageReactionCounts(int $pageId,int $userId):array
    {
        $stmt=$this->pdo->prepare("SELECT reaction,COUNT(*) count,MAX(user_id=?) mine FROM `{$this->prefix}page_reactions` WHERE page_id=? GROUP BY reaction");$stmt->execute([$userId,$pageId]);$out=['helpful'=>['count'=>0,'mine'=>false],'like'=>['count'=>0,'mine'=>false]];foreach($stmt->fetchAll() as $r)$out[$r['reaction']]=['count'=>(int)$r['count'],'mine'=>(bool)$r['mine']];return $out;
    }
    public function toggleCommentReaction(int $commentId,int $userId,string $reaction):void
    {
        if(!in_array($reaction,['like','thanks','confirm'],true))throw new RuntimeException('Invalid reaction.');$stmt=$this->pdo->prepare("SELECT c.page_id FROM `{$this->prefix}comments` c WHERE c.id=? AND c.deleted_at IS NULL");$stmt->execute([$commentId]);$pageId=(int)($stmt->fetchColumn()?:0);$page=$this->pages->find($pageId);if(!$page||!$this->authz->canViewPage($userId,$page))throw new RuntimeException('Forbidden.');
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}comment_reactions` WHERE comment_id=? AND user_id=? AND reaction=?");$check->execute([$commentId,$userId,$reaction]);if($check->fetchColumn())$this->pdo->prepare("DELETE FROM `{$this->prefix}comment_reactions` WHERE comment_id=? AND user_id=? AND reaction=?")->execute([$commentId,$userId,$reaction]);else$this->pdo->prepare("INSERT INTO `{$this->prefix}comment_reactions` (comment_id,user_id,reaction,created_at) VALUES (?,?,?,UTC_TIMESTAMP())")->execute([$commentId,$userId,$reaction]);
    }
    public function commentReactionCounts(int $pageId,int $userId):array
    {
        $stmt=$this->pdo->prepare("SELECT cr.comment_id,cr.reaction,COUNT(*) count,MAX(cr.user_id=?) mine FROM `{$this->prefix}comment_reactions` cr JOIN `{$this->prefix}comments` c ON c.id=cr.comment_id WHERE c.page_id=? GROUP BY cr.comment_id,cr.reaction");$stmt->execute([$userId,$pageId]);$out=[];foreach($stmt->fetchAll() as $r)$out[(int)$r['comment_id']][$r['reaction']]=['count'=>(int)$r['count'],'mine'=>(bool)$r['mine']];return $out;
    }
    public function setThreadStatus(int $commentId,int $userId,string $status):int
    {
        if(!in_array($status,['open','resolved'],true))throw new RuntimeException('Invalid status.');$stmt=$this->pdo->prepare("SELECT c.page_id,c.parent_id,c.user_id FROM `{$this->prefix}comments` c WHERE c.id=? AND c.deleted_at IS NULL");$stmt->execute([$commentId]);$comment=$stmt->fetch();if(!$comment)throw new RuntimeException('Missing comment.');$page=$this->pages->find((int)$comment['page_id']);if(!$page||(! $this->authz->canEditPage($userId,$page)&&(int)$comment['user_id']!==$userId))throw new RuntimeException('Forbidden.');$root=(int)($comment['parent_id']?:$commentId);$this->pdo->prepare("UPDATE `{$this->prefix}comments` SET thread_status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$root]);return (int)$comment['page_id'];
    }
    public function createInline(int $pageId,int $userId,string $quote,string $body,string $before='',string $after=''):int
    {
        $page=$this->pages->find($pageId);if(!$page||!$this->authz->canCommentPage($userId,$page))throw new RuntimeException('Forbidden.');$quote=trim(strip_tags($quote));$body=trim($body);if($quote===''||mb_strlen($quote)>1000||$body===''||mb_strlen($body)>10000)throw new RuntimeException('Invalid input.');$plain=preg_replace('/\s+/u',' ',trim(strip_tags((string)$page['content'])))??'';$needle=preg_replace('/\s+/u',' ',$quote)??$quote;if(!str_contains($plain,$needle))throw new RuntimeException('Selected text no longer matches current page.');
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}inline_comments` (page_id,user_id,page_version,quote_text,context_before,context_after,body,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$pageId,$userId,(int)$page['version_no'],$quote,mb_substr($before,0,500),mb_substr($after,0,500),$body]);return (int)$this->pdo->lastInsertId();
    }
    public function inlineForPage(int $pageId):array{$stmt=$this->pdo->prepare("SELECT ic.*,u.username,CONCAT(u.first_name,' ',u.last_name) author_name FROM `{$this->prefix}inline_comments` ic JOIN `{$this->prefix}users` u ON u.id=ic.user_id WHERE ic.page_id=? ORDER BY ic.created_at DESC LIMIT 200");$stmt->execute([$pageId]);return $stmt->fetchAll();}
    public function setInlineStatus(int $id,int $userId,string $status):int
    {
        if(!in_array($status,['open','resolved'],true))throw new RuntimeException('Invalid status.');$stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}inline_comments` WHERE id=?");$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Missing inline comment.');$page=$this->pages->find((int)$row['page_id']);if(!$page||(!$this->authz->canEditPage($userId,$page)&&(int)$row['user_id']!==$userId))throw new RuntimeException('Forbidden.');$this->pdo->prepare("UPDATE `{$this->prefix}inline_comments` SET status=?,resolved_at=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$status==='resolved'?gmdate('Y-m-d H:i:s'):null,$id]);return (int)$row['page_id'];
    }
}
