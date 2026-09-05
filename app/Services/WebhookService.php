<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Authorization;
use ImWiki\Security\Crypto;
use ImWiki\Security\SsrfGuard;
use PDO;
use RuntimeException;

final class WebhookService
{
    public const EVENTS=['page.created','page.updated','page.deleted','comment.created'];
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Authorization $authz,private readonly Crypto $crypto,private readonly SsrfGuard $ssrf,private readonly JobQueueService $jobs){}
    public function list(int $spaceId,int $userId):array{if(!$this->authz->canManageSpace($userId,$spaceId))throw new RuntimeException('Forbidden.');$s=$this->pdo->prepare("SELECT id,space_id,name,endpoint_url,events_json,enabled,created_at,updated_at FROM `{$this->prefix}webhooks` WHERE space_id=? ORDER BY id DESC");$s->execute([$spaceId]);$rows=$s->fetchAll();foreach($rows as &$r)$r['events']=json_decode((string)$r['events_json'],true)?:[];return $rows;}
    /** @return array{id:int,secret:string} */
    public function create(int $spaceId,int $userId,string $name,string $url,array $events):array
    {
        if(!$this->authz->canManageSpace($userId,$spaceId))throw new RuntimeException('Forbidden.');$name=trim($name);if($name===''||mb_strlen($name)>150)throw new RuntimeException('Invalid name.');$this->ssrf->validate($url);$events=array_values(array_intersect(self::EVENTS,array_unique(array_map('strval',$events))));if(!$events)throw new RuntimeException('Select event.');$secret=bin2hex(random_bytes(32));$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}webhooks` (space_id,name,endpoint_url,secret_hash,secret_encrypted,events_json,enabled,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$spaceId,$name,$url,hash('sha256',$secret),$this->crypto->encrypt($secret),json_encode($events,JSON_THROW_ON_ERROR),$userId]);return ['id'=>(int)$this->pdo->lastInsertId(),'secret'=>$secret];
    }
    public function revoke(int $id,int $spaceId,int $userId):void{if(!$this->authz->canManageSpace($userId,$spaceId))throw new RuntimeException('Forbidden.');$this->pdo->prepare("UPDATE `{$this->prefix}webhooks` SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE id=? AND space_id=?")->execute([$id,$spaceId]);}
    public function enqueueEvent(string $event,array $payload):void
    {
        if(!in_array($event,self::EVENTS,true))return;$spaceId=(int)($payload['space_id']??0);if($spaceId<=0)return;$stmt=$this->pdo->prepare("SELECT id FROM `{$this->prefix}webhooks` WHERE space_id=? AND enabled=1 AND JSON_CONTAINS(events_json,JSON_QUOTE(?))");$stmt->execute([$spaceId,$event]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)$this->jobs->enqueue('webhook',['webhook_id'=>(int)$id,'event'=>$event,'payload'=>$payload]);
    }
    public function deliver(array $job):void
    {
        $id=(int)($job['webhook_id']??0);$event=(string)($job['event']??'');if($id<=0||!in_array($event,self::EVENTS,true))throw new RuntimeException('Invalid webhook job.');$stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}webhooks` WHERE id=? AND enabled=1");$stmt->execute([$id]);$hook=$stmt->fetch();if(!$hook)return;$dest=$this->ssrf->validate((string)$hook['endpoint_url']);$secret=$this->crypto->decrypt((string)$hook['secret_encrypted']);$body=json_encode(['event'=>$event,'timestamp'=>gmdate(DATE_ATOM),'data'=>$job['payload']??[]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$sig='sha256='.hash_hmac('sha256',$body,$secret);$this->post($dest,$body,$sig);
    }
    private function post(array $dest,string $body,string $signature):void
    {
        $tls=$dest['scheme']==='https';$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$dest['host'],'SNI_enabled'=>true,'disable_compression'=>true]]);$target=($tls?'tls':'tcp').'://'.(str_contains($dest['ip'],':')?'['.$dest['ip'].']':$dest['ip']).':'.$dest['port'];$errno=0;$err='';$socket=@stream_socket_client($target,$errno,$err,5,STREAM_CLIENT_CONNECT,$context);if(!$socket)throw new RuntimeException('Webhook connection failed.');stream_set_timeout($socket,5);$hostHeader=$dest['host'].((($tls&&$dest['port']!==443)||(!$tls&&$dest['port']!==80))?':'.$dest['port']:'');$req="POST {$dest['path']} HTTP/1.1\r\nHost: {$hostHeader}\r\nContent-Type: application/json\r\nUser-Agent: imWiki/".IMWIKI_VERSION."\r\nX-imWiki-Signature: {$signature}\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}";fwrite($socket,$req);$line=fgets($socket,4096)?:'';fclose($socket);if(!preg_match('#^HTTP/\d(?:\.\d)?\s+(2\d\d)\b#',$line))throw new RuntimeException('Webhook returned a non-success response.');
    }
}
