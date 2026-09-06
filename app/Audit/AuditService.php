<?php
declare(strict_types=1);

namespace ImWiki\Audit;

use ImWiki\Support\SecretMasker;
use PDO;
use RuntimeException;

final class AuditService
{
    /** @param AuditExporterInterface[] $exporters */
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly string $root,private readonly array $exporters=[]){ }

    public function record(?int $userId,string $action,string $category,string $severity,string $resourceType,?int $resourceId,string $description,string $ip,string $userAgent,array $metadata=[]):int
    {
        $safe=SecretMasker::mask($metadata);$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}audit_log` (user_id,action,category,severity,request_id,resource_type,resource_id,description,metadata_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");$stmt->execute([$userId,$action,$category,$severity,defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,$resourceType,$resourceId,mb_substr((string)SecretMasker::mask($description),0,2000),json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),mb_substr($ip,0,64),mb_substr($userAgent,0,500)]);$id=(int)$this->pdo->lastInsertId();$event=$this->detail($id)??[];foreach($this->exporters as $exporter){try{$exporter->export($event);}catch(\Throwable){}}return$id;
    }

    public function query(array $filters=[],int $page=1,int $perPage=50):array
    {
        $page=max(1,$page);$perPage=max(1,min(200,$perPage));[$where,$args]=$this->filters($filters);$base=" FROM `{$this->prefix}audit_log` a LEFT JOIN `{$this->prefix}users` u ON u.id=a.user_id WHERE ".implode(' AND ',$where);$c=$this->pdo->prepare('SELECT COUNT(*)'.$base);$c->execute($args);$total=(int)$c->fetchColumn();$stmt=$this->pdo->prepare("SELECT a.id,a.user_id,u.username,a.action,a.category,a.severity,a.request_id,a.resource_type,a.resource_id,a.description,a.ip_address,a.user_agent,a.created_at".$base." ORDER BY a.id DESC LIMIT {$perPage} OFFSET ".(($page-1)*$perPage));$stmt->execute($args);return['items'=>$stmt->fetchAll()?:[],'page'=>$page,'per_page'=>$perPage,'total'=>$total,'next'=>$page*$perPage<$total?$page+1:null];
    }

    public function detail(int $id):?array
    {
        $s=$this->pdo->prepare("SELECT a.*,u.username FROM `{$this->prefix}audit_log` a LEFT JOIN `{$this->prefix}users` u ON u.id=a.user_id WHERE a.id=? LIMIT 1");$s->execute([$id]);$row=$s->fetch();if(!$row)return null;$row['metadata']=SecretMasker::mask(json_decode((string)($row['metadata_json']??'{}'),true)?:[]);unset($row['metadata_json']);$row['description']=SecretMasker::mask((string)$row['description']);$row['user_agent']=SecretMasker::mask((string)$row['user_agent']);return$row;
    }

    public function export(array $filters,string $format,int $actorId):array
    {
        if(!in_array($format,['csv','json'],true))throw new \InvalidArgumentException('Unsupported audit export format.');[$where,$args]=$this->filters($filters);$stmt=$this->pdo->prepare("SELECT a.id,a.user_id,u.username,a.action,a.category,a.severity,a.request_id,a.resource_type,a.resource_id,a.description,a.ip_address,a.user_agent,a.metadata_json,a.created_at FROM `{$this->prefix}audit_log` a LEFT JOIN `{$this->prefix}users` u ON u.id=a.user_id WHERE ".implode(' AND ',$where)." ORDER BY a.id LIMIT 100000");$stmt->execute($args);$rows=[];foreach($stmt->fetchAll() as $row){$row['description']=SecretMasker::mask((string)$row['description']);$row['user_agent']=SecretMasker::mask((string)$row['user_agent']);$meta=SecretMasker::mask(json_decode((string)($row['metadata_json']??'{}'),true)?:[]);$row['metadata_json']=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$rows[]=$row;}$dir=$this->root.'/storage/private/audit-exports';if(!is_dir($dir)&&!@mkdir($dir,0770,true))throw new RuntimeException('Cannot create audit export directory.');$name='audit-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.'.$format;$path=$dir.'/'.$name;if($format==='json'){$payload=json_encode(['generated_at'=>gmdate('c'),'filters'=>$filters,'records'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($path,$payload,LOCK_EX)===false)throw new RuntimeException('Cannot write audit export.');}else{$f=fopen($path,'wb');if(!$f)throw new RuntimeException('Cannot write audit export.');$header=['id','user_id','username','action','category','severity','request_id','resource_type','resource_id','description','ip_address','user_agent','metadata_json','created_at'];fputcsv($f,$header);foreach($rows as $row)fputcsv($f,array_map(static fn(string $k):mixed=>$row[$k]??null,$header));fclose($f);}@chmod($path,0640);$sha=hash_file('sha256',$path);if($sha===false)throw new RuntimeException('Cannot checksum audit export.');$this->record($actorId,'audit.exported','audit','info','audit_export',null,'Audit log exported','system','audit-service',['format'=>$format,'checksum_sha256'=>$sha,'records'=>count($rows)]);return['path'=>$path,'filename'=>$name,'checksum_sha256'=>$sha,'size_bytes'=>(int)filesize($path),'records'=>count($rows)];
    }

    private function filters(array $filters):array
    {
        $where=['1=1'];$args=[];$map=['user_id'=>['a.user_id','int'],'action'=>['a.action','string'],'category'=>['a.category','string'],'resource_type'=>['a.resource_type','string'],'severity'=>['a.severity','string'],'ip'=>['a.ip_address','string'],'request_id'=>['a.request_id','string']];foreach($map as $key=>[$column,$type])if(isset($filters[$key])&&$filters[$key]!==''){$where[]=$column.'=?';$args[]=$type==='int'?(int)$filters[$key]:mb_substr((string)$filters[$key],0,190);}if(!empty($filters['resource_id'])){$where[]='a.resource_id=?';$args[]=(int)$filters['resource_id'];}if(!empty($filters['date_from'])){$where[]='a.created_at>=?';$args[]=gmdate('Y-m-d 00:00:00',strtotime((string)$filters['date_from'])?:time());}if(!empty($filters['date_to'])){$where[]='a.created_at<=?';$args[]=gmdate('Y-m-d 23:59:59',strtotime((string)$filters['date_to'])?:time());}return[$where,$args];
    }
}
