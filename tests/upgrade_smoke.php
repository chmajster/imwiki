<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Database\Migrator;

$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('TEST_DB_PORT')?:3306);$dbName=getenv('TEST_DB_NAME')?:'imwiki_test';$user=getenv('TEST_DB_USER')?:'imwiki';$pass=getenv('TEST_DB_PASS')?:'imwiki';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',(string)$table).'`');$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$tmp=sys_get_temp_dir().'/imwiki-migrations-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('Cannot create migration temp dir.');
try{
    foreach(['001_initial_schema.php','002_collaboration_and_security.php','003_api_and_content_workflow.php','004_security_sharing_operations.php'] as $name){if(!copy($root.'/database/migrations/'.$name,$tmp.'/'.$name))throw new RuntimeException('Cannot copy '.$name);}
    $old=new Migrator($pdo,$tmp,'');$ran=$old->migrate();if(count($ran)!==4)throw new RuntimeException('Baseline migration count mismatch.');
    if($old->pending()!==[])throw new RuntimeException('Baseline migrations still pending.');

    $full=new Migrator($pdo,$root.'/database/migrations','');$pending=$full->pending();if(count($pending)!==5)throw new RuntimeException('Expected five upgrade migrations, got '.count($pending));
    $full->migrate();if($full->pending()!==[])throw new RuntimeException('Upgrade left pending migrations.');
    $column=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pages' AND COLUMN_NAME='pre_archive_status'")->fetchColumn();if((int)$column!==1)throw new RuntimeException('Hardening archive column missing after upgrade.');
    $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND INDEX_NAME='idx_jobs_status_available'")->fetchColumn();if((int)$index<1)throw new RuntimeException('Queue index missing after upgrade.');
    $backupTable=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='backup_artifacts'")->fetchColumn();if((int)$backupTable!==1)throw new RuntimeException('Async backup table missing after upgrade.');

    $pdo->prepare("DELETE FROM migrations WHERE migration=?")->execute(['008_hardening_reliability.php']);
    $retry=$full->migrate();if(!in_array('008_hardening_reliability.php',$retry,true)||$full->pending()!==[])throw new RuntimeException('Idempotent migration retry failed.');
    echo "UPGRADE_SMOKE_OK\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);
}
