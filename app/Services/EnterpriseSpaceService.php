<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Exceptions\AuthorizationException;
use ImWiki\Exceptions\ConflictException;
use ImWiki\Exceptions\ValidationException;
use ImWiki\Security\Authorization;
use ImWiki\Support\FeatureFlags;
use PDO;

final class EnterpriseSpaceService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Authorization $authz,private readonly FeatureFlags $flags){}

    public function categories(int $userId):array
    {
        if($userId<=0)throw new AuthorizationException();$sql="SELECT c.id,c.name,c.sort_order,COUNT(s.id) space_count FROM `{$this->prefix}space_categories` c LEFT JOIN `{$this->prefix}spaces` s ON s.category_id=c.id AND s.deleted_at IS NULL GROUP BY c.id ORDER BY c.sort_order,c.name";return$this->pdo->query($sql)->fetchAll()?:[];
    }

    public function createCategory(int $userId,string $name):int
    {
        $this->requireAdmin($userId);$name=trim($name);if($name===''||mb_strlen($name)>150)throw new ValidationException('Invalid category name.');$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}space_categories` (name,sort_order,created_at,updated_at) VALUES (?,COALESCE((SELECT MAX(x.sort_order)+10 FROM `{$this->prefix}space_categories` x),10),UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$name]);return(int)$this->pdo->lastInsertId();
    }

    public function renameCategory(int $userId,int $id,string $name):void{$this->requireAdmin($userId);$name=trim($name);if($name===''||mb_strlen($name)>150)throw new ValidationException('Invalid category name.');$this->pdo->prepare("UPDATE `{$this->prefix}space_categories` SET name=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$name,$id]);}
    public function reorderCategories(int $userId,array $ids):void{$this->requireAdmin($userId);$ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $v):bool=>$v>0)));$this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}space_categories` SET sort_order=?,updated_at=UTC_TIMESTAMP() WHERE id=?");foreach($ids as $i=>$id)$stmt->execute([($i+1)*10,$id]);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}}
    public function moveSpaceToCategory(int $userId,int $spaceId,?int $categoryId):void{$this->requireManage($userId,$spaceId);if($categoryId!==null){$s=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}space_categories` WHERE id=?");$s->execute([$categoryId]);if((int)$s->fetchColumn()!==1)throw new ValidationException('Unknown category.');}$this->pdo->prepare("UPDATE `{$this->prefix}spaces` SET category_id=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL")->execute([$categoryId,$spaceId]);}

    public function personalSpace(int $userId,bool $create=true):?array
    {
        $s=$this->pdo->prepare("SELECT * FROM `{$this->prefix}spaces` WHERE personal_owner_id=? AND deleted_at IS NULL LIMIT 1");$s->execute([$userId]);$row=$s->fetch();if($row||!$create)return$row?:null;if(!$this->flags->enabled('personal_spaces')||$this->setting('personal_spaces.enabled','0')!=='1')throw new AuthorizationException('Personal Spaces are disabled.');$u=$this->pdo->prepare("SELECT username,first_name,last_name FROM `{$this->prefix}users` WHERE id=? AND status='active' AND deleted_at IS NULL");$u->execute([$userId]);$user=$u->fetch();if(!$user)throw new AuthorizationException();$key='~'.mb_strtoupper((string)$user['username']);$visibility=$this->setting('personal_spaces.default_visibility','private');if(!in_array($visibility,['logged_in','private','restricted'],true))$visibility='private';$quota=max(0,(int)$this->setting('personal_spaces.default_quota_bytes','0'));$classification=$this->classificationId($this->setting('content.default_classification','internal'));
        $this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}spaces` (name,space_key,description,owner_id,personal_owner_id,visibility,lifecycle,storage_quota_bytes,default_classification_id,created_at,updated_at) VALUES (?,?,?,?,?,?,'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$display=trim((string)$user['first_name'].' '.(string)$user['last_name'])?:((string)$user['username']);$stmt->execute(['Personal Space — '.$display,$key,'Personal Space',$userId,$userId,$visibility,$quota?:null,$classification]);$id=(int)$this->pdo->lastInsertId();$this->pdo->commit();$q=$this->pdo->prepare("SELECT * FROM `{$this->prefix}spaces` WHERE id=?");$q->execute([$id]);return$q->fetch()?:null;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof \PDOException&&str_contains($e->getMessage(),'Duplicate')){return$this->personalSpace($userId,false);}throw$e;}
    }

    public function setLifecycle(int $userId,int $spaceId,string $lifecycle,?string $reviewDate=null,?string $archiveDate=null,?int $retentionPolicyId=null):void
    {
        $this->requireManage($userId,$spaceId);if(!in_array($lifecycle,['active','read_only','archived','scheduled_deletion'],true))throw new ValidationException('Invalid Space lifecycle.');if($reviewDate!==null&&$reviewDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reviewDate))throw new ValidationException('Invalid review date.');if($archiveDate!==null&&$archiveDate!==''&&strtotime($archiveDate)===false)throw new ValidationException('Invalid archive date.');if($retentionPolicyId){$s=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}retention_policies` WHERE id=? AND enabled=1");$s->execute([$retentionPolicyId]);if((int)$s->fetchColumn()!==1)throw new ValidationException('Unknown retention policy.');}
        $archived=$lifecycle==='archived'?gmdate('Y-m-d H:i:s'):null;$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}spaces` SET lifecycle=?,review_date=?,retention_policy_id=?,archive_date=?,archived_at=CASE WHEN ?='archived' THEN COALESCE(archived_at,UTC_TIMESTAMP()) WHEN ?='active' THEN NULL ELSE archived_at END,updated_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL");$stmt->execute([$lifecycle,$reviewDate?:null,$retentionPolicyId?:null,$archiveDate?gmdate('Y-m-d H:i:s',strtotime($archiveDate)):null,$lifecycle,$lifecycle,$spaceId]);
    }

    public function setOwner(int $userId,int $spaceId,int $ownerId):void{$this->requireManage($userId,$spaceId);$u=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}users` WHERE id=? AND status='active' AND deleted_at IS NULL");$u->execute([$ownerId]);if((int)$u->fetchColumn()!==1)throw new ValidationException('Owner must be an active user.');$this->pdo->prepare("UPDATE `{$this->prefix}spaces` SET owner_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$ownerId,$spaceId]);}

    public function createFromTemplate(int $userId,array $data):int
    {
        if(!$this->authz->can($userId,'spaces.create')&&!$this->authz->isAdmin($userId))throw new AuthorizationException();$type=(string)($data['template']??'blank');if(!in_array($type,['blank','team','documentation','project','knowledge_base'],true))throw new ValidationException('Invalid Space template.');$name=trim((string)($data['name']??''));$key=mb_strtoupper(trim((string)($data['key']??'')));$ownerId=(int)($data['owner_id']??$userId);if($name===''||mb_strlen($name)>190||!preg_match('/^[~A-Z0-9_-]{2,50}$/',$key))throw new ValidationException('Invalid Space name or key.');$exists=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}spaces` WHERE space_key=?");$exists->execute([$key]);if((int)$exists->fetchColumn()>0)throw new ConflictException('Space key already exists.');$visibility=(string)($data['visibility']??($type==='team'?'restricted':'logged_in'));if(!in_array($visibility,['logged_in','private','restricted'],true))$visibility='logged_in';$classification=$this->classificationId((string)($data['classification']??$this->setting('content.default_classification','internal')));
        $this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}spaces` (name,space_key,description,owner_id,visibility,lifecycle,default_classification_id,team_id,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$name,$key,mb_substr(trim((string)($data['description']??'')),0,2000),$ownerId,$visibility,$classification,(int)($data['team_id']??0)?:null]);$spaceId=(int)$this->pdo->lastInsertId();$templates=['blank'=>[['Home','<h1>'.$this->e($name).'</h1><p></p>']],'team'=>[['Home','<h1>'.$this->e($name).'</h1><h2>Team</h2><p></p>']],'documentation'=>[['Home','<h1>'.$this->e($name).'</h1><h2>Documentation</h2><p>{{children}}</p>']],'knowledge_base'=>[['Home','<h1>'.$this->e($name).'</h1><p>{{recently-updated}}</p>']],'project'=>[['Home','<h1>'.$this->e($name).'</h1><h2>Overview</h2><p></p>'],['Decisions','<h1>Decisions</h1><p>{{content-by-label:decision}}</p>'],['Meetings','<h1>Meetings</h1><p>{{children}}</p>'],['Documentation','<h1>Documentation</h1><p>{{children}}</p>']]];foreach($templates[$type] as [$title,$content]){$slug=$this->slug($title);$uuid=$this->uuid();$p=$this->pdo->prepare("INSERT INTO `{$this->prefix}pages` (uuid,space_id,title,slug,content,status,restriction_mode,classification_id,version_no,author_id,last_editor_id,owner_id,created_at,updated_at) VALUES (?,?,?, ?,?,'published','inherited',?,1,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$p->execute([$uuid,$spaceId,$title,$slug,$content,$classification,$userId,$userId,$ownerId]);$pid=(int)$this->pdo->lastInsertId();$this->pdo->prepare("INSERT INTO `{$this->prefix}page_versions` (page_id,version_no,title,content,properties_json,author_id,change_comment,created_at) VALUES (?,1,?,?,JSON_ARRAY(),?,'Space template',UTC_TIMESTAMP())")->execute([$pid,$title,$content,$userId]);if($title==='Home')$this->pdo->prepare("UPDATE `{$this->prefix}spaces` SET homepage_page_id=? WHERE id=?")->execute([$pid,$spaceId]);}$this->pdo->commit();return$spaceId;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function listWithHierarchy(int $userId):array
    {
        $admin=$this->authz->isAdmin($userId);$sql="SELECT s.id,s.name,s.space_key,s.description,s.lifecycle,s.visibility,s.category_id,s.personal_owner_id,s.review_date,s.archive_date,c.name category_name,c.sort_order category_order,u.username owner_username FROM `{$this->prefix}spaces` s LEFT JOIN `{$this->prefix}space_categories` c ON c.id=s.category_id JOIN `{$this->prefix}users` u ON u.id=s.owner_id WHERE s.deleted_at IS NULL ORDER BY c.sort_order IS NULL,c.sort_order,c.name,s.name";$rows=$this->pdo->query($sql)->fetchAll();return array_values(array_filter($rows,fn(array $r):bool=>$admin||$this->authz->canViewSpace($userId,(int)$r['id'])));
    }

    private function requireAdmin(int $uid):void{if(!$this->authz->canAdmin($uid,'admin.system'))throw new AuthorizationException();}
    private function requireManage(int $uid,int $spaceId):void{if(!$this->authz->canManageSpace($uid,$spaceId))throw new AuthorizationException();}
    private function setting(string $key,string $default=''):string{$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
    private function classificationId(string $key):?int{$s=$this->pdo->prepare("SELECT id FROM `{$this->prefix}classifications` WHERE classification_key=? AND enabled=1 LIMIT 1");$s->execute([$key]);$id=(int)($s->fetchColumn()?:0);return$id>0?$id:null;}
    private function slug(string $title):string{$s=mb_strtolower($title);if(function_exists('transliterator_transliterate'))$s=transliterator_transliterate('Any-Latin; Latin-ASCII',$s)?:$s;$s=preg_replace('/[^a-z0-9]+/u','-',$s)??'page';return trim($s,'-')?:'page';}
    private function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);$h=bin2hex($d);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
