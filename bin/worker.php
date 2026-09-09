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
use ImWiki\Security\Crypto;
use ImWiki\Security\SsrfGuard;
use ImWiki\Services\BackupArtifactService;
use ImWiki\Services\BackupService;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\JobRunner;
use ImWiki\Services\MailService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\SmtpClient;
use ImWiki\Services\WebhookService;
use ImWiki\Support\Config;

$db=(array)Config::get('db',[]);$pdo=Connection::create($db);$pdo->exec("SET time_zone = '+00:00'");$prefix=(string)($db['prefix']??'');
$users=new UserRepository($pdo,$prefix);$pages=new PageRepository($pdo,$prefix);$authz=new Authorization($pdo,$users,$prefix);$jobs=new JobQueueService($pdo,$prefix);$crypto=new Crypto((string)Config::get('app.secret',''));
$notifications=new NotificationService($pdo,$prefix,$authz,$pages,$jobs);$mail=new MailService($pdo,$prefix,$crypto,new SmtpClient());$webhooks=new WebhookService($pdo,$prefix,$authz,$crypto,new SsrfGuard(),$jobs);$backupService=new BackupService($pdo,$prefix,$root);$backupArtifacts=new BackupArtifactService($pdo,$prefix,$root,$backupService,$jobs);$runner=new JobRunner($jobs,$mail,$crypto,$webhooks,$notifications,$backupArtifacts);

$daemon=in_array('--daemon',$argv,true);$limit=50;$sleep=5;
foreach($argv as $arg){if(str_starts_with($arg,'--limit='))$limit=max(1,min(100,(int)substr($arg,8)));if(str_starts_with($arg,'--sleep='))$sleep=max(1,min(60,(int)substr($arg,8)));}
if($daemon)set_time_limit(0);

do{
    $started=microtime(true);
    try{$done=$runner->run($limit,true);$status=['ok'=>true,'processed'=>count($done),'ids'=>$done,'pending'=>$jobs->pendingCount(),'failed'=>$jobs->failedCount(),'duration_ms'=>(int)((microtime(true)-$started)*1000)];echo json_encode($status,JSON_UNESCAPED_SLASHES).PHP_EOL;}
    catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'error'=>get_class($e),'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);if(!$daemon)exit(1);$done=[];}
    if($daemon&&count($done)===0)sleep($sleep);
}while($daemon);
