<?php
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/config/config.php')||!is_file($root.'/storage/installed.lock')){
    fwrite(STDERR,"imWiki is not installed.\n");exit(2);
}
require $root.'/bootstrap.php';

use ImWiki\Database\Connection;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\DigestService;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\RetentionService;
use ImWiki\Services\SchedulerService;
use ImWiki\Support\Config;

$db=(array)Config::get('db',[]);$pdo=Connection::create($db);$pdo->exec("SET time_zone = '+00:00'");$prefix=(string)($db['prefix']??'');
$users=new UserRepository($pdo,$prefix);$pages=new PageRepository($pdo,$prefix);$authz=new Authorization($pdo,$users,$prefix);$jobs=new JobQueueService($pdo,$prefix);$notifications=new NotificationService($pdo,$prefix,$authz,$pages,$jobs);$digest=new DigestService($pdo,$prefix,$notifications,$jobs);$retention=new RetentionService($pdo,$prefix,$root);$scheduler=new SchedulerService($retention,$digest,$root.'/storage/cache');

try{$result=$scheduler->runNow();echo json_encode(['ok'=>true,'result'=>$result,'pending_jobs'=>$jobs->pendingCount()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'error'=>get_class($e),'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);exit(1);}
