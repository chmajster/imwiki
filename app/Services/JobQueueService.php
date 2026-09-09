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
        if(!preg_match('/^[a-z0-9_.-]{1,100}$/',$type))throw new \InvalidArgumentException('Invalid job type.');
        $when=$availableAt?:gmdate('Y-m-d H:i:s');
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}jobs` (job_type,payload_json,status,available_at,created_at) VALUES (?,?,'pending',?,UTC_TIMESTAMP())");
        $stmt->execute([$type,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$when]);
        return (int)$this->pdo->lastInsertId();
    }

    public function process(int $limit,array $handlers):array
    {
        $this->recoverStale();
        $done=[];$limit=max(0,min(100,$limit));
        for($i=0;$i<$limit;$i++){
            $job=$this->reserve();if(!$job)break;$id=(int)$job['id'];
            try{
                $payload=json_decode((string)$job['payload_json'],true,512,JSON_THROW_ON_ERROR);
                $handler=$handlers[(string)$job['job_type']]??null;
                if(!is_callable($handler))throw new \RuntimeException('No handler for job type.');
                $handler($payload);
                $this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='done',finished_at=UTC_TIMESTAMP(),reserved_at=NULL,last_error=NULL WHERE id=?")->execute([$id]);
                $done[]=$id;
            }catch(Throwable $e){
                $attempts=(int)$job['attempts']+1;$failed=$attempts>=5;
                $delay=min(60,5*(2**max(0,$attempts-1)));
                $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status=?,attempts=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),reserved_at=NULL,last_error=? WHERE id=?");
                $stmt->execute([$failed?'failed':'pending',$attempts,$delay,mb_substr($e->getMessage(),0,1000),$id]);
            }
        }
        return $done;
    }

    public function recoverStale(int $leaseSeconds=600):int
    {
        $leaseSeconds=max(60,min(86400,$leaseSeconds));
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='pending',reserved_at=NULL,available_at=UTC_TIMESTAMP(),last_error=COALESCE(last_error,'Recovered stale reservation') WHERE status='running' AND reserved_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND)");
        $stmt->bindValue(1,$leaseSeconds,PDO::PARAM_INT);$stmt->execute();
        return $stmt->rowCount();
    }

    public function retryFailed(?string $type=null):int
    {
        if($type!==null&&!preg_match('/^[a-z0-9_.-]{1,100}$/',$type))throw new \InvalidArgumentException('Invalid job type.');
        if($type===null){
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='pending',attempts=0,reserved_at=NULL,available_at=UTC_TIMESTAMP(),finished_at=NULL WHERE status='failed'");$stmt->execute();
        }else{
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='pending',attempts=0,reserved_at=NULL,available_at=UTC_TIMESTAMP(),finished_at=NULL WHERE status='failed' AND job_type=?");$stmt->execute([$type]);
        }
        return $stmt->rowCount();
    }

    private function reserve():?array
    {
        $stmt=$this->pdo->query("SELECT * FROM `{$this->prefix}jobs` WHERE status='pending' AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1");
        $job=$stmt->fetch();if(!$job)return null;
        $update=$this->pdo->prepare("UPDATE `{$this->prefix}jobs` SET status='running',reserved_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
        $update->execute([(int)$job['id']]);if($update->rowCount()!==1)return null;
        return $job;
    }

    public function pendingCount():int{return (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status IN ('pending','running')")->fetchColumn();}
    public function failedCount():int{return (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status='failed'")->fetchColumn();}
}
