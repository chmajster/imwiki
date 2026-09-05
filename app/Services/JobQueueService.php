<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;
use Throwable;

final class JobQueueService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}
    public function enqueue(string $type,array $payload,?string $availableAt=null):int
    {
        if(!preg_match('/^[a-z0-9_.-]{1,100}$/',$type))throw new \InvalidArgumentException('Invalid job type.');$when=$availableAt?:gmdate('Y-m-d H:i:s');$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}jobs` (job_type,payload_json,status,available_at,created_at) VALUES (?,?,'pending',?,UTC_TIMESTAMP())");$stmt->execute([$type,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$when]);return (int)$this->pdo->lastInsertId();
    }
    public function process(int $limit,array $handlers):array
    {
        $done=[];$limit=max(0,min(20,$limit));for($i=0;$i<$limit;$i++){$job=$this->reserve();if(!$job)break;$id=(int)$job['id'];try{$payload=json_decode((string)$job['payload_json'],true,512,JSON_THROW_ON_ERROR);$handler=$handlers[(string)$job['job_type']]??null;if(!is_callable($handler))throw new \RuntimeException('No handler for job type.');$handler($payload);$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='done',finished_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?")->execute([$id]);$done[]=$id;}catch(Throwable $e){$attempts=(int)$job['attempts']+1;$failed=$attempts>=5;$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status=?,attempts=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),reserved_at=NULL,last_error=? WHERE id=?");$stmt->execute([$failed?'failed':'pending',$attempts,mb_substr($e->getMessage(),0,1000),$id]);}}
        return $done;
    }
    private function reserve():?array
    {
        $stmt=$this->pdo->query("SELECT * FROM `{$this->prefix}jobs` WHERE status='pending' AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1");$job=$stmt->fetch();if(!$job)return null;$update=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='running',reserved_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");$update->execute([(int)$job['id']]);if($update->rowCount()!==1)return null;return $job;
    }
    public function pendingCount():int{return (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status IN ('pending','running')")->fetchColumn();}
}
