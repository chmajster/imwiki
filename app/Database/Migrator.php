<?php
declare(strict_types=1);

namespace ImWiki\Database;

use ImWiki\Support\SecretMasker;
use PDO;
use Throwable;

final class Migrator
{
    private const LOCK_TIMEOUT_SECONDS=0;

    public function __construct(private readonly PDO $pdo,private readonly string $directory,private readonly string $prefix=''){}

    public function migrate():array
    {
        $this->ensureInfrastructure();
        if(!$this->acquireLock())throw new \RuntimeException('Database migration is already running in another request.');
        try{
            $state=$this->state();if(($state['status']??'')==='failed')throw new \RuntimeException('Previous migration failed. Review diagnostics and explicitly retry from upgrade.php.');
            $executed=$this->executed();$batch=(int)$this->pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM `'.$this->prefix.'migrations`')->fetchColumn();$ran=[];
            foreach($this->files() as $file){$name=basename($file);if(in_array($name,$executed,true))continue;$this->setState('running',$name,null);
                try{$migration=require$file;if(is_callable($migration)){$migration($this->pdo,$this->prefix);}elseif(is_array($migration)){foreach($migration as $statement)$this->pdo->exec(str_replace('{{prefix}}',$this->prefix,(string)$statement));}else throw new \RuntimeException('Invalid migration: '.$name);
                    $stmt=$this->pdo->prepare('INSERT INTO `'.$this->prefix.'migrations` (migration,batch,executed_at) VALUES (?,?,UTC_TIMESTAMP())');$stmt->execute([$name,$batch]);$ran[]=$name;$executed[]=$name;$this->setState('idle',null,null);
                }catch(Throwable $e){$safe=(string)SecretMasker::mask($e->getMessage());$this->setState('failed',$name,mb_substr($safe,0,1000));throw$e;}
            }
            $this->setState('idle',null,null);return$ran;
        }finally{$this->releaseLock();}
    }

    public function retryFailed():array
    {
        $this->ensureInfrastructure();$this->setState('idle',null,null);return$this->migrate();
    }

    public function pending():array
    {
        $this->ensureInfrastructure();$executed=$this->executed();return array_values(array_filter(array_map('basename',$this->files()),static fn(string $name):bool=>!in_array($name,$executed,true)));
    }

    public function dryRun():array
    {
        $this->ensureInfrastructure();$pending=$this->pending();$executed=$this->executed();$state=$this->state();return['current_schema_version'=>$executed?end($executed):'none','target_schema_version'=>($files=$this->files())?basename(end($files)):'none','pending'=>$pending,'pending_count'=>count($pending),'state'=>$state,'backup_recommended'=>count($pending)>0];
    }

    public function executed():array
    {
        $this->ensureInfrastructure();return$this->pdo->query('SELECT migration FROM `'.$this->prefix.'migrations` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function state():array
    {
        $this->ensureInfrastructure();$stmt=$this->pdo->query("SELECT status,current_migration,last_error,updated_at FROM `{$this->prefix}migration_state` WHERE id=1");return$stmt->fetch()?:['status'=>'idle','current_migration'=>null,'last_error'=>null,'updated_at'=>null];
    }

    private function files():array{$files=glob(rtrim($this->directory,'/').'/*.php')?:[];sort($files,SORT_STRING);return$files;}

    private function ensureInfrastructure():void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `'.$this->prefix.'migrations` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) NOT NULL UNIQUE,batch INT NOT NULL,executed_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->prefix}migration_state` (id TINYINT UNSIGNED PRIMARY KEY,status ENUM('idle','running','failed') NOT NULL DEFAULT 'idle',current_migration VARCHAR(255) NULL,last_error VARCHAR(1000) NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("INSERT IGNORE INTO `{$this->prefix}migration_state` (id,status,updated_at) VALUES (1,'idle',UTC_TIMESTAMP())");
    }

    private function setState(string $status,?string $migration,?string $error):void{$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}migration_state` SET status=?,current_migration=?,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=1");$stmt->execute([$status,$migration,$error]);}
    private function lockName():string{return'imwiki:migrate:'.substr(hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn().':'.$this->prefix),0,48);}
    private function acquireLock():bool{$stmt=$this->pdo->prepare('SELECT GET_LOCK(?,?)');$stmt->execute([$this->lockName(),self::LOCK_TIMEOUT_SECONDS]);return(int)$stmt->fetchColumn()===1;}
    private function releaseLock():void{try{$stmt=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$this->lockName()]);}catch(Throwable){}}
}
