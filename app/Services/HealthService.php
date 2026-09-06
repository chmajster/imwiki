<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Database\Migrator;
use ImWiki\Storage\StorageManager;
use PDO;
use Throwable;

final class HealthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly string $root,
        private readonly Migrator $migrator,
        private readonly JobQueueService $jobs,
        private readonly StorageManager $storage,
    ) {}

    public function readiness():array
    {
        $checks=[
            'database'=>false,
            'migrations'=>false,
            'storage'=>false,
        ];
        try{$checks['database']=(string)$this->pdo->query('SELECT 1')->fetchColumn()==='1';}catch(Throwable){}
        try{$checks['migrations']=$this->migrator->pending()===[]&&(($this->migrator->state()['status']??'idle')==='idle');}catch(Throwable){}
        $storageDir=$this->root.'/storage';
        $checks['storage']=is_dir($storageDir)&&is_writable($storageDir);
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks];
    }

    public function detailed():array
    {
        $ready=$this->readiness();
        $schema=$this->migrator->dryRun();
        $storage=$this->storage->dashboard();
        $counts=[
            'pages'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE deleted_at IS NULL")->fetchColumn(),
            'spaces'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}spaces` WHERE deleted_at IS NULL")->fetchColumn(),
            'active_users'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL")->fetchColumn(),
            'failed_jobs'=>(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status IN ('failed','dead')")->fetchColumn(),
            'pending_jobs'=>$this->jobs->pendingCount(),
        ];
        return [
            'ready'=>$ready['ready'],
            'checks'=>$ready['checks'],
            'app_version'=>defined('IMWIKI_VERSION')?IMWIKI_VERSION:'unknown',
            'php_version'=>PHP_VERSION,
            'schema'=>[
                'current'=>$schema['current_schema_version']??'unknown',
                'target'=>$schema['target_schema_version']??'unknown',
                'pending_count'=>$schema['pending_count']??0,
                'state'=>$schema['state']['status']??'unknown',
            ],
            'counts'=>$counts,
            'storage'=>$storage,
            'request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,
        ];
    }
}
