<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register($root);

use ImWiki\Services\InstallerService;

$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('TEST_DB_PORT')?:3306);$dbName=getenv('TEST_DB_NAME')?:'imwiki_test';$user=getenv('TEST_DB_USER')?:'imwiki';$pass=getenv('TEST_DB_PASS')?:'imwiki';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',(string)$table).'`');$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$configPath=$root.'/config/config.php';$lockPath=$root.'/storage/installed.lock';@unlink($configPath);@unlink($lockPath);
$installer=new InstallerService($root);
$db=['host'=>$host,'port'=>$port,'database'=>$dbName,'username'=>$user,'password'=>$pass,'prefix'=>''];
[$ok,$message]=$installer->testDatabase($db);if(!$ok)throw new RuntimeException('Database test failed: '.$message);
$app=['site_name'=>'imWiki CI','app_url'=>'http://127.0.0.1:8080','language'=>'pl','timezone'=>'Europe/Warsaw'];
$admin=['email'=>'admin@example.invalid','username'=>'admin-ci','first_name'=>'CI','last_name'=>'Admin','password'=>'Strong-test-password-123'];
try{
    $installer->install($db,$app,$admin);
    if(!is_file($configPath)||!is_file($lockPath))throw new RuntimeException('Installer did not create config and lock files.');
    $config=require $configPath;if(($config['app']['name']??null)!=='imWiki CI'||strlen((string)($config['app']['secret']??''))<32)throw new RuntimeException('Generated configuration is invalid.');
    $adminId=(int)$pdo->query("SELECT id FROM users WHERE username='admin-ci' AND status='active'")->fetchColumn();if($adminId<=0)throw new RuntimeException('Installer did not create administrator.');
    $isAdmin=(int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id={$adminId} AND r.name='administrator'")->fetchColumn();if($isAdmin!==1)throw new RuntimeException('Installer administrator role is missing.');
    if((int)$pdo->query("SELECT COUNT(*) FROM spaces WHERE space_key='WELCOME'")->fetchColumn()!==1)throw new RuntimeException('WELCOME space missing.');
    if((int)$pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn()<8)throw new RuntimeException('Not all migrations were installed.');
    echo "INSTALL_SMOKE_OK\n";
}finally{@unlink($configPath);@unlink($lockPath);}
