<?php
declare(strict_types=1);
namespace ImWiki\Services;
use PDO;

final class SearchService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix){}

    public function parse(string $input):array
    {
        $filters=[];$rest=$input;
        preg_match_all('/\b(space|author|owner|label|type|status):(?:"([^"]+)"|([^\s]+))/iu',$input,$matches,PREG_SET_ORDER);
        foreach($matches as $m){$key=mb_strtolower($m[1]);$value=trim($m[2]!==''?$m[2]:$m[3]);if($value!=='')$filters[$key][]=$value;$rest=str_replace($m[0],' ',$rest);}
        $text=trim(preg_replace('/\s+/u',' ',$rest)??'');return['text'=>$text,'filters'=>$filters];
    }

    public function search(string $input,int $limit=50):array
    {
        $parsed=$this->parse($input);$text=$parsed['text'];$f=$parsed['filters'];$where=["p.deleted_at IS NULL","p.status<>'archived'"];$params=[];
        if(isset($f['space'])){$ph=[];foreach($f['space'] as $i=>$v){$k='space'.$i;$ph[]=':'.$k;$params[$k]=mb_strtoupper($v);} $where[]='s.space_key IN ('.implode(',',$ph).')';}
        if(isset($f['author'])){$ph=[];foreach($f['author'] as $i=>$v){$k='author'.$i;$ph[]=':'.$k;$params[$k]=$v;} $where[]='au.username IN ('.implode(',',$ph).')';}
        if(isset($f['owner'])){$ph=[];foreach($f['owner'] as $i=>$v){$k='owner'.$i;$ph[]=':'.$k;$params[$k]=$v;} $where[]='ou.username IN ('.implode(',',$ph).')';}
        if(isset($f['status'])){$valid=array_values(array_intersect(['draft','in_review','approved','published'],array_map('mb_strtolower',$f['status'])));if($valid){$ph=[];foreach($valid as $i=>$v){$k='status'.$i;$ph[]=':'.$k;$params[$k]=$v;}$where[]='p.status IN ('.implode(',',$ph).')';}}
        if(isset($f['type'])&&!in_array('page',array_map('mb_strtolower',$f['type']),true))return[];
        if(isset($f['label'])){foreach($f['label'] as $i=>$v){$k='label'.$i;$where[]="EXISTS (SELECT 1 FROM `{$this->prefix}page_labels` pl JOIN `{$this->prefix}labels` l ON l.id=pl.label_id WHERE pl.page_id=p.id AND l.name=:{$k})";$params[$k]=$v;}}
        if($text!==''){$params['exact']=$text;$params['like']='%'.$text.'%';$where[]='(p.title LIKE :like OR p.content LIKE :like2 OR EXISTS (SELECT 1 FROM `'.$this->prefix.'page_labels` pl2 JOIN `'.$this->prefix.'labels` l2 ON l2.id=pl2.label_id WHERE pl2.page_id=p.id AND l2.name LIKE :like3))';$params['like2']=$params['like'];$params['like3']=$params['like'];}
        $score=$text!==''?'(CASE WHEN p.title=:exact THEN 1000 WHEN p.title LIKE :titleprefix THEN 700 WHEN p.title LIKE :titlelike THEN 500 ELSE 100 END + CASE WHEN EXISTS (SELECT 1 FROM `'.$this->prefix.'page_labels` pl3 JOIN `'.$this->prefix.'labels` l3 ON l3.id=pl3.label_id WHERE pl3.page_id=p.id AND l3.name LIKE :labellike) THEN 250 ELSE 0 END)':'100';
        if($text!==''){$params['titleprefix']=$text.'%';$params['titlelike']='%'.$text.'%';$params['labellike']='%'.$text.'%';}
        $sql="SELECT p.id,p.title,p.status,p.updated_at,s.name space_name,s.space_key,au.username author_username,ou.username owner_username,{$score} score FROM `{$this->prefix}pages` p JOIN `{$this->prefix}spaces` s ON s.id=p.space_id JOIN `{$this->prefix}users` au ON au.id=p.author_id LEFT JOIN `{$this->prefix}users` ou ON ou.id=p.owner_id WHERE ".implode(' AND ',$where)." ORDER BY score DESC,p.updated_at DESC LIMIT :limit";
        $stmt=$this->pdo->prepare($sql);foreach($params as $k=>$v)$stmt->bindValue(':'.$k,$v);$stmt->bindValue(':limit',max(1,min(100,$limit)),PDO::PARAM_INT);$stmt->execute();return$stmt->fetchAll();
    }
}
