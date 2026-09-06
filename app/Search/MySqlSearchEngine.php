<?php
declare(strict_types=1);

namespace ImWiki\Search;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class MySqlSearchEngine implements SearchEngineInterface
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz){}
    public function key():string{return'mysql';}

    public function search(string $query,int $userId,int $limit=50,array $filters=[]):array
    {
        $query=trim($query);$limit=max(1,min(100,$limit));if($query===''&& !$filters)return[];$where=["p.deleted_at IS NULL","p.status NOT IN ('archived','expired')"];$args=[];
        if($query!==''){$where[]='(MATCH(p.title,p.content) AGAINST (? IN NATURAL LANGUAGE MODE) OR p.title LIKE ? OR p.content LIKE ? OR EXISTS (SELECT 1 FROM `'.$this->prefix.'page_aliases` pa WHERE pa.page_id=p.id AND pa.alias LIKE ?) OR EXISTS (SELECT 1 FROM `'.$this->prefix.'labels` l JOIN `'.$this->prefix.'page_labels` pl ON pl.label_id=l.id WHERE pl.page_id=p.id AND l.name LIKE ?))';$args=[$query,'%'.$query.'%','%'.$query.'%','%'.$query.'%','%'.$query.'%'];}
        if(!empty($filters['space'])){$values=array_values(array_filter((array)$filters['space']));if($values){$where[]='s.space_key IN ('.implode(',',array_fill(0,count($values),'?')).')';array_push($args,...array_map('mb_strtoupper',$values));}}
        if(!empty($filters['owner'])){$values=array_values(array_filter((array)$filters['owner']));if($values){$where[]='ou.username IN ('.implode(',',array_fill(0,count($values),'?')).')';array_push($args,...$values);}}
        if(!empty($filters['author'])){$values=array_values(array_filter((array)$filters['author']));if($values){$where[]='au.username IN ('.implode(',',array_fill(0,count($values),'?')).')';array_push($args,...$values);}}
        if(!empty($filters['status'])){$values=array_values(array_intersect(['draft','in_review','approved','published','deprecated'],array_map('mb_strtolower',(array)$filters['status'])));if($values){$where[]='p.status IN ('.implode(',',array_fill(0,count($values),'?')).')';array_push($args,...$values);}}
        if(!empty($filters['label']))foreach((array)$filters['label'] as $label){$where[]="EXISTS (SELECT 1 FROM `{$this->prefix}page_labels` plf JOIN `{$this->prefix}labels` lf ON lf.id=plf.label_id WHERE plf.page_id=p.id AND lf.name=?)";$args[]=mb_strtolower((string)$label);}
        $score=$query!==''?'(CASE WHEN p.title=? THEN 1000 WHEN p.title LIKE ? THEN 700 WHEN MATCH(p.title,p.content) AGAINST (? IN NATURAL LANGUAGE MODE)>0 THEN 500 ELSE 100 END)':'100';$scoreArgs=$query!==''?[$query,$query.'%',$query]:[];
        $sql="SELECT p.id,p.title,p.content,p.status,p.updated_at,p.space_id,s.name space_name,s.space_key,au.username author_username,ou.username owner_username,{$score} score FROM `{$this->prefix}pages` p JOIN `{$this->prefix}spaces` s ON s.id=p.space_id JOIN `{$this->prefix}users` au ON au.id=p.author_id LEFT JOIN `{$this->prefix}users` ou ON ou.id=p.owner_id WHERE ".implode(' AND ',$where)." ORDER BY score DESC,p.updated_at DESC LIMIT ".min(300,$limit*4);$stmt=$this->pdo->prepare($sql);$stmt->execute(array_merge($scoreArgs,$args));$results=[];foreach($stmt->fetchAll() as $row){$page=$this->pages->find((int)$row['id']);if(!$page||!$this->authz->canViewPage($userId,$page))continue;$row['excerpt']=$this->excerpt((string)$row['content'],$query);$row['matched_terms']=$query!==''?$this->terms($query):[];unset($row['content']);$results[]=$row;if(count($results)>=$limit)break;}
        if($query!=='')$this->metric('search.count',1);return$results;
    }

    public function attachmentSearch(string $query,int $userId,int $limit=25):array
    {
        $query=trim($query);if($query==='')return[];$stmt=$this->pdo->prepare("SELECT a.id,a.page_id,a.original_name,a.description,a.mime_type,a.size_bytes,a.checksum_sha256 FROM `{$this->prefix}attachments` a WHERE a.deleted_at IS NULL AND a.scan_status<>'infected' AND (a.original_name LIKE ? OR a.description LIKE ? OR a.mime_type LIKE ?) ORDER BY a.created_at DESC LIMIT ".max(1,min(100,$limit*4)));$like='%'.$query.'%';$stmt->execute([$like,$like,$like]);$out=[];foreach($stmt->fetchAll() as $row){$p=$this->pages->find((int)$row['page_id']);if($p&&$this->authz->canViewPage($userId,$p)){$out[]=$row;if(count($out)>=$limit)break;}}return$out;
    }

    public function rebuildBatch(int $cursorId=0,int $limit=250):array
    {
        $limit=max(10,min(1000,$limit));$stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}pages` WHERE id>? AND deleted_at IS NULL ORDER BY id LIMIT ".$limit);$stmt->execute([$cursorId]);$ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));$next=$ids?max($ids):null;$done=count($ids)<$limit;$count=(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE deleted_at IS NULL")->fetchColumn();$this->pdo->prepare("INSERT INTO `{$this->prefix}search_index_state` (engine_key,indexed_pages,last_rebuild_at,cursor_id,status,last_error,updated_at) VALUES ('mysql',?,?,?,'idle',NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE indexed_pages=VALUES(indexed_pages),last_rebuild_at=VALUES(last_rebuild_at),cursor_id=VALUES(cursor_id),status=VALUES(status),last_error=NULL,updated_at=UTC_TIMESTAMP()")->execute([$count,$done?gmdate('Y-m-d H:i:s'):null,$done?null:$next]);return['processed'=>count($ids),'next_cursor'=>$done?null:$next,'done'=>$done,'indexed_pages'=>$count];
    }
    public function status():array{$s=$this->pdo->prepare("SELECT * FROM `{$this->prefix}search_index_state` WHERE engine_key='mysql'");$s->execute();return$s->fetch()?:['engine_key'=>'mysql','status'=>'idle','indexed_pages'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE deleted_at IS NULL")->fetchColumn()];}
    private function excerpt(string $html,string $query):string{$text=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');if($text==='')return'';$pos=$query!==''?mb_stripos($text,$query):false;$start=$pos===false?0:max(0,$pos-120);$snippet=mb_substr($text,$start,320);return($start>0?'…':'').$snippet.(mb_strlen($text)>$start+320?'…':'');}
    private function terms(string $query):array{return array_slice(array_values(array_unique(array_filter(preg_split('/\s+/u',mb_strtolower($query))?:[],static fn(string $v):bool=>mb_strlen($v)>1))),0,10);}
    private function metric(string $key,int $delta):void{$this->pdo->prepare("INSERT INTO `{$this->prefix}application_metrics` (metric_key,metric_date,metric_value,updated_at) VALUES (?,UTC_DATE(),?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE metric_value=metric_value+VALUES(metric_value),updated_at=UTC_TIMESTAMP()")->execute([$key,$delta]);}
}
