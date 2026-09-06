<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Support\SecretMasker;
use PDO;
use Throwable;

final class JobQueueService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function enqueue(string $type,array $payload,?string $availableAt=null,string $priority='normal',?string $dedupeKey=null,int $maxAttempts=5):int
    {
        if(!preg_match('/^[a-z][a-z0-9._-]{1,99}$/',$type))throw new \InvalidArgumentException('Invalid job type.');if(!in_array($priority,['high','normal','low'],true))throw new \InvalidArgumentException('Invalid job priority.');$maxAttempts=max(1,min(20,$maxAttempts));$when=$availableAt?:gmdate('Y-m-d H:i:s');$dedupeKey=$dedupeKey!==null?mb_substr(trim($dedupeKey),0,190):null;if($dedupeKey!==''){$s=$this->pdo->prepare("SELECT id FROM `{$this->prefix}jobs` WHERE dedupe_key=? AND status IN ('pending','running') LIMIT 1");$s->execute([$dedupeKey]);$existing=(int)($s->fetchColumn()?:0);if($existing>0)return$existing;}$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}jobs` (job_type,priority,payload_json,dedupe_key,status,attempts,max_attempts,available_at,created_at,updated_at) VALUES (?,?,?,?, 'pending',0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([$type,$priority,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$dedupeKey?:null,$maxAttempts,$when]);return(int)$this->pdo->lastInsertId();
    }

    /** @return int[] completed job ids */
    public function process(int $limit,array $handlers):array
    {
        $done=[];$limit=max(0,min(100,$limit));$worker=substr(hash('sha256',gethostname().':'.getmypid().':'.bin2hex(random_bytes(8))),0,32);for($i=0;$i<$limit;$i++){$job=$this->reserve($worker);if(!$job)break;$id=(int)$job['id'];try{$payload=json_decode((string)$job['payload_json'],true,512,JSON_THROW_ON_ERROR);$handler=$handlers[(string)$job['job_type']]??null;if(!is_callable($handler))throw new \RuntimeException('No handler for job type.');$handler($payload,$job);$this->complete($id,$worker);$done[]=$id;}catch(Throwable $e){$this->fail($job,$worker,$e);}}return$done;
    }

    public function pendingCount():int{return(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status IN ('pending','running')")->fetchColumn();}
    public function dashboard(int $limit=100):array{$counts=[];foreach($this->pdo->query("SELECT status,COUNT(*) c FROM `{$this->prefix}jobs` GROUP BY status")->fetchAll() as $r)$counts[$r['status']]=(int)$r['c'];$stmt=$this->pdo->query("SELECT id,job_type,priority,status,attempts,max_attempts,available_at,reserved_at,finished_at,last_error,error_code,created_at,updated_at FROM `{$this->prefix}jobs` ORDER BY FIELD(status,'failed','dead','running','pending','done','discarded'),id DESC LIMIT ".max(1,min(500,$limit)));return['counts'=>$counts,'jobs'=>$stmt->fetchAll()?:[]];}
    public function retry(int $id):void{$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='pending',attempts=0,available_at=UTC_TIMESTAMP(),reserved_at=NULL,locked_by=NULL,lock_expires_at=NULL,finished_at=NULL,last_error=NULL,error_code=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('failed','dead')")->execute([$id]);}
    public function discard(int $id):void{$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='discarded',locked_by=NULL,lock_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','failed','dead')")->execute([$id]);}
    public function cleanup(int $days=30):int{$days=max(1,min(3650,$days));return$this->pdo->exec("DELETE FROM `{$this->prefix}jobs` WHERE status IN ('done','discarded') AND COALESCE(finished_at,updated_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)");}

    private function reserve(string $worker):?array
    {
        $lease=max(30,min(900,(int)$this->setting('jobs.lease_seconds','120')));$this->pdo->beginTransaction();try{$this->pdo->exec("UPDATE `{$this->prefix}jobs` SET status='pending',locked_by=NULL,lock_expires_at=NULL,reserved_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status='running' AND lock_expires_at IS NOT NULL AND lock_expires_at<UTC_TIMESTAMP() AND attempts<max_attempts");$this->pdo->exec("UPDATE `{$this->prefix}jobs` SET status='dead',locked_by=NULL,lock_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status IN ('pending','running','failed') AND attempts>=max_attempts");$stmt=$this->pdo->query("SELECT * FROM `{$this->prefix}jobs` WHERE status='pending' AND available_at<=UTC_TIMESTAMP() ORDER BY FIELD(priority,'high','normal','low'),id LIMIT 1 FOR UPDATE");$job=$stmt->fetch();if(!$job){$this->pdo->commit();return null;}$update=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='running',attempts=attempts+1,reserved_at=UTC_TIMESTAMP(),locked_by=?,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");$update->execute([$worker,$lease,(int)$job['id']]);if($update->rowCount()!==1){$this->pdo->rollBack();return null;}$this->pdo->commit();$job['attempts']=(int)$job['attempts']+1;$job['locked_by']=$worker;return$job;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function complete(int $id,string $worker):void{$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='done',finished_at=UTC_TIMESTAMP(),locked_by=NULL,lock_expires_at=NULL,last_error=NULL,error_code=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='running' AND locked_by=?")->execute([$id,$worker]);}
    private function fail(array $job,string $worker,Throwable $e):void{$attempt=(int)$job['attempts'];$max=(int)($job['max_attempts']??5);$dead=$attempt>=$max;$backoff=min(21600,30*(2**max(0,$attempt-1)));$message=mb_substr((string)SecretMasker::mask($e->getMessage()),0,1000);$code=mb_substr(preg_replace('/[^A-Za-z0-9_.-]/','_',get_class($e))??'job_error',0,100);$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status=?,available_at=?,reserved_at=NULL,locked_by=NULL,lock_expires_at=NULL,last_error=?,error_code=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND locked_by=?");$stmt->execute([$dead?'dead':'pending',$dead?null:gmdate('Y-m-d H:i:s',time()+$backoff),$message,$code,(int)$job['id'],$worker]);}
    private function setting(string $key,string $default):string{$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
}
