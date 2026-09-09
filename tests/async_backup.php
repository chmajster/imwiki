<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;
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
use ImWiki\Services\RetentionService;
use ImWiki\Services\SmtpClient;
use ImWiki\Services\WebhookService;

$host=getenv('TEST_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('TEST_DB_PORT')?:3306);
$dbName=getenv('TEST_DB_NAME')?:'imwiki_test';
$user=getenv('TEST_DB_USER')?:'imwiki';
$pass=getenv('TEST_DB_PASS')?:'imwiki';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',(string)$table).'`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
(new Migrator($pdo,$root.'/database/migrations',''))->migrate();

$pdo->exec("INSERT INTO users (username,first_name,last_name,email,password_hash,status,language,timezone,theme,created_at,updated_at) VALUES ('backup-admin','Backup','Admin','backup-admin@example.invalid','x','active','pl','Europe/Warsaw','system',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO spaces (name,space_key,owner_id,visibility,created_at,updated_at) VALUES ('Async Backup','ASYNC',?,'logged_in',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$uid]);
$sid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO pages (space_id,title,slug,content,status,version_no,author_id,last_editor_id,owner_id,restriction_mode,created_at,updated_at) VALUES (?,'Async backup page','async-backup-page','<p>queued, not synchronous</p>','published',1,?,?,?,'inherited',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$sid,$uid,$uid,$uid]);

$configPath=$root.'/config/config.php';
$existingConfig=is_file($configPath)?file_get_contents($configPath):null;
$config=['app'=>['name'=>'imWiki Test','url'=>'http://localhost','language'=>'pl','timezone'=>'Europe/Warsaw','secret'=>str_repeat('s',64)],'db'=>['host'=>$host,'port'=>$port,'database'=>$dbName,'username'=>$user,'password'=>$pass,'prefix'=>'']];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
if(!defined('IMWIKI_VERSION'))define('IMWIKI_VERSION',trim((string)file_get_contents($root.'/VERSION')));

$createdFiles=[];
try{
    $jobs=new JobQueueService($pdo,'');
    $backupService=new BackupService($pdo,'',$root);
    $artifacts=new BackupArtifactService($pdo,'',$root,$backupService,$jobs);

    $artifactId=$artifacts->request($uid);
    $row=$pdo->query("SELECT * FROM backup_artifacts WHERE id={$artifactId}")->fetch();
    if(!$row||$row['status']!=='queued'||$row['stored_name']!==null)throw new RuntimeException('Backup request performed work synchronously or has invalid initial state.');
    $job=$pdo->query("SELECT * FROM jobs WHERE job_type='backup' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch();
    if(!$job||((int)(json_decode((string)$job['payload_json'],true)['artifact_id']??0))!==$artifactId)throw new RuntimeException('Backup job was not queued.');

    $users=new UserRepository($pdo,'');$pages=new PageRepository($pdo,'');$authz=new Authorization($pdo,$users,'');$crypto=new Crypto(str_repeat('s',64));
    $notifications=new NotificationService($pdo,'',$authz,$pages,$jobs);$mail=new MailService($pdo,'',$crypto,new SmtpClient());$webhooks=new WebhookService($pdo,'',$authz,$crypto,new SsrfGuard(),$jobs);
    $runner=new JobRunner($jobs,$mail,$crypto,$webhooks,$notifications,$artifacts);
    $done=$runner->run(1);
    if(count($done)!==1)throw new RuntimeException('Worker did not process queued backup job.');

    $ready=$pdo->query("SELECT * FROM backup_artifacts WHERE id={$artifactId}")->fetch();
    if(!$ready||$ready['status']!=='ready'||!preg_match('/^[a-f0-9]{48}\.zip$/',(string)$ready['stored_name']))throw new RuntimeException('Backup artifact did not become ready.');
    $resolved=$artifacts->resolveReady($artifactId);
    if(!$resolved||!is_file((string)$resolved['path']))throw new RuntimeException('Ready backup cannot be resolved for download.');
    $createdFiles[]=(string)$resolved['path'];

    $retryId=$artifacts->request($uid);
    $retryJobId=(int)$pdo->query("SELECT id FROM jobs WHERE job_type='backup' AND status='pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE jobs SET status='done',finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$retryJobId]);
    $pdo->prepare("UPDATE backup_artifacts SET status='failed',error_message='synthetic failure',finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$retryId]);
    $artifacts->retry($retryId,$uid);
    $retryState=$pdo->query("SELECT status FROM backup_artifacts WHERE id={$retryId}")->fetchColumn();
    if($retryState!=='queued')throw new RuntimeException('Failed backup was not re-queued.');
    if(count($runner->run(1))!==1)throw new RuntimeException('Retry job was not processed.');
    $retryReady=$artifacts->resolveReady($retryId);
    if(!$retryReady)throw new RuntimeException('Retried backup did not become ready.');
    $createdFiles[]=(string)$retryReady['path'];

    $pdo->prepare("UPDATE backup_artifacts SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?")->execute([$artifactId]);
    $retention=new RetentionService($pdo,'',$root);
    $cleanup=$retention->cleanup();
    if((int)($cleanup['backup_artifacts']??0)<1)throw new RuntimeException('Retention did not expire backup artifact.');
    if($pdo->query("SELECT status FROM backup_artifacts WHERE id={$artifactId}")->fetchColumn()!=='expired')throw new RuntimeException('Expired backup status was not persisted.');
    if(is_file($createdFiles[0]))throw new RuntimeException('Expired backup file was not deleted.');

    $routes=(string)file_get_contents($root.'/app/Bootstrap/RouteRegistrar.php');
    foreach(["/admin/backup/{id}/download","/admin/backup/{id}/retry"] as $route){if(!str_contains($routes,$route))throw new RuntimeException('Async backup route missing: '.$route);}
    $worker=(string)file_get_contents($root.'/bin/worker.php');
    if(!str_contains($worker,'BackupArtifactService')||!str_contains($worker,'$backupArtifacts'))throw new RuntimeException('CLI worker is not wired for backup jobs.');

    echo "ASYNC_BACKUP_OK\n";
}finally{
    foreach($createdFiles as $path)if(is_file($path))@unlink($path);
    if($existingConfig!==null)file_put_contents($configPath,$existingConfig);else @unlink($configPath);
}
