<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PageOperationService;
use ImWiki\Services\PagePropertyService;
use ImWiki\Services\PageService;
use ImWiki\Services\RetentionService;
use ImWiki\Services\SearchQuery;
use ImWiki\Services\SessionService;

$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('TEST_DB_PORT')?:3306);$dbName=getenv('TEST_DB_NAME')?:'imwiki_test';$dbUser=getenv('TEST_DB_USER')?:'imwiki';$dbPass=getenv('TEST_DB_PASS')?:'imwiki';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',(string)$table).'`');$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
(new Migrator($pdo,$root.'/database/migrations',''))->migrate();

$now=gmdate('Y-m-d H:i:s');
$userStmt=$pdo->prepare("INSERT INTO users (username,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)");
$userStmt->execute(['owner','owner@example.invalid',password_hash('Owner-password-123',PASSWORD_DEFAULT),$now,$now]);$owner=(int)$pdo->lastInsertId();
$userStmt->execute(['viewer','viewer@example.invalid',password_hash('Viewer-password-123',PASSWORD_DEFAULT),$now,$now]);$viewer=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO roles (name,label,created_at) VALUES ('administrator','Administrator',?)")->execute([$now]);$adminRole=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO user_roles (user_id,role_id) VALUES (?,?)")->execute([$owner,$adminRole]);
$pdo->prepare("INSERT INTO spaces (name,space_key,owner_id,visibility,created_at,updated_at) VALUES ('Docs','DOCS',?,'logged_in',?,?)")->execute([$owner,$now,$now]);$space=(int)$pdo->lastInsertId();

$users=new UserRepository($pdo);$pages=new PageRepository($pdo);$authz=new Authorization($pdo,$users);$pageService=new PageService($pdo);$notifications=new NotificationService($pdo,'',$authz,$pages,new JobQueueService($pdo));

// Search must filter ACL before LIMIT. Create many high-ranked inaccessible pages and one accessible result.
$insertPage=$pdo->prepare("INSERT INTO pages (space_id,title,slug,content,status,restriction_mode,version_no,author_id,last_editor_id,owner_id,created_at,updated_at) VALUES (?,?,?,?, 'published',?,1,?,?,?, ?,?)");
for($i=0;$i<80;$i++){
    $ts=gmdate('Y-m-d H:i:s',time()-$i);$insertPage->execute([$space,'needle private '.$i,'needle-private-'.$i,'<p>needle</p>','specific',$owner,$owner,$owner,$ts,$ts]);
}
$old=gmdate('Y-m-d H:i:s',time()-1000);$insertPage->execute([$space,'needle accessible','needle-accessible','<p>needle</p>','inherited',$owner,$owner,$owner,$old,$old]);$accessibleId=(int)$pdo->lastInsertId();
$results=SearchQuery::run($pdo,'','needle',$viewer,false,10);$ids=array_map(static fn(array $r):int=>(int)$r['id'],$results);if(!in_array($accessibleId,$ids,true))throw new RuntimeException('ACL-aware search lost an accessible result behind inaccessible rows.');foreach($results as $row)if(str_contains((string)$row['title'],'private'))throw new RuntimeException('ACL-aware search leaked a restricted page.');

// Stale running jobs must be recoverable.
$pdo->prepare("INSERT INTO jobs (job_type,payload_json,status,attempts,available_at,reserved_at,created_at) VALUES ('test','{}','running',0,UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())")->execute();$jobs=new JobQueueService($pdo);if($jobs->recoverStale(600)!==1)throw new RuntimeException('Stale job was not recovered.');if((string)$pdo->query("SELECT status FROM jobs WHERE job_type='test' ORDER BY id DESC LIMIT 1")->fetchColumn()!=='pending')throw new RuntimeException('Recovered job is not pending.');

// Version restore must restore property snapshots, not only content.
$setting=$pdo->prepare("UPDATE settings SET setting_value='0' WHERE setting_key='workflow.status_enabled'");$setting->execute();
$restorePage=$pageService->create($space,null,'Restore target','<p>content</p>',$owner);$props=new PagePropertyService($pdo,'',$pages,$authz);$props->set($restorePage,'priority','Priority','text','old','',$owner);$versionOld=(int)$pdo->query('SELECT version_no FROM pages WHERE id='.$restorePage)->fetchColumn();$props->set($restorePage,'priority','Priority','text','new','',$owner);$pageService->restore($restorePage,$versionOld,$owner);$restored=(string)$pdo->query("SELECT value_text FROM page_properties WHERE page_id={$restorePage} AND property_key='priority'")->fetchColumn();if($restored!=='old')throw new RuntimeException('Page property snapshot was not restored.');

// Archive/unarchive must preserve the workflow status.
$pdo->prepare("UPDATE pages SET status='in_review' WHERE id=?")->execute([$restorePage]);$ops=new PageOperationService($pdo,'',$pages,$authz,$pageService);$ops->archive($restorePage,$owner,true);$ops->archive($restorePage,$owner,false);if((string)$pdo->query('SELECT status FROM pages WHERE id='.$restorePage)->fetchColumn()!=='in_review')throw new RuntimeException('Unarchive did not restore previous workflow status.');

// Purge must remove an entire deleted subtree.
$parent=$pageService->create($space,null,'Trash parent','<p>x</p>',$owner);$child=$pageService->create($space,$parent,'Trash child','<p>y</p>',$owner);$ops->trash($parent,$owner);$ops->purge($parent,$owner);$remaining=(int)$pdo->query("SELECT COUNT(*) FROM pages WHERE id IN ({$parent},{$child})")->fetchColumn();if($remaining!==0)throw new RuntimeException('Purge left deleted subtree rows behind.');

// Attachment garbage collection must keep referenced blobs and delete orphan blobs.
$uploadDir=$root.'/storage/uploads';if(!is_dir($uploadDir))mkdir($uploadDir,0770,true);$referenced=str_repeat('a',48);$orphan=str_repeat('b',48);file_put_contents($uploadDir.'/'.$referenced,'ref');file_put_contents($uploadDir.'/'.$orphan,'orphan');
try{
    $pdo->prepare("INSERT INTO attachments (page_id,uploader_id,original_name,stored_name,mime_type,size_bytes,current_version,created_at) VALUES (?,?, 'ref.txt',?,'text/plain',3,1,UTC_TIMESTAMP())")->execute([$restorePage,$owner,$referenced]);$attachment=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO attachment_versions (attachment_id,version_no,stored_name,mime_type,size_bytes,checksum_sha256,uploader_id,created_at) VALUES (?,1,?,'text/plain',3,NULL,?,UTC_TIMESTAMP())")->execute([$attachment,$referenced,$owner]);
    $retention=new RetentionService($pdo,'',$root);if($retention->garbageCollectUploads()!==1||!is_file($uploadDir.'/'.$referenced)||is_file($uploadDir.'/'.$orphan))throw new RuntimeException('Upload garbage collection invariant failed.');
}finally{@unlink($uploadDir.'/'.$referenced);@unlink($uploadDir.'/'.$orphan);}

// Credential rotation must revoke sessions, API tokens and reset links together.
$sessionRaw='session-test-key';$pdo->prepare("INSERT INTO user_sessions (user_id,session_key_hash,ip_address,user_agent,created_at,last_seen_at) VALUES (?,?, '127.0.0.1','test',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$viewer,hash('sha256',$sessionRaw)]);$pdo->prepare("INSERT INTO api_tokens (user_id,name,token_prefix,token_hash,scopes_json,created_at) VALUES (?, 'test','imw_test',?, '[]',UTC_TIMESTAMP())")->execute([$viewer,hash('sha256','api-test')]);$pdo->prepare("INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,created_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())")->execute([$viewer,hash('sha256','reset-test')]);
$sessions=new SessionService($pdo);$sessions->revokeCredentialsForUser($viewer,false);if((int)$pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id={$viewer} AND revoked_at IS NULL")->fetchColumn()!==0)throw new RuntimeException('Session remained active after credential rotation.');if((int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE user_id={$viewer} AND revoked_at IS NULL")->fetchColumn()!==0)throw new RuntimeException('API token remained active after credential rotation.');if((int)$pdo->query("SELECT COUNT(*) FROM password_reset_tokens WHERE user_id={$viewer} AND used_at IS NULL")->fetchColumn()!==0)throw new RuntimeException('Reset token remained active after credential rotation.');

// Unread count must not silently cap at 250.
$notif=$pdo->prepare("INSERT INTO notifications (user_id,type,created_at) VALUES (?,'security.test',UTC_TIMESTAMP())");for($i=0;$i<300;$i++)$notif->execute([$viewer]);if($notifications->unreadCount($viewer)!==300)throw new RuntimeException('Unread notification count is truncated.');

echo "RELIABILITY_INTEGRATION_OK\n";
