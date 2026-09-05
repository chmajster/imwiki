<?php
declare(strict_types=1);

if (!is_file(__DIR__.'/storage/installed.lock') || !is_file(__DIR__.'/config/config.php')) {
    header('Location: install.php');exit;
}
require_once __DIR__.'/bootstrap.php';

use ImWiki\Database\Connection;
use ImWiki\Database\Migrator;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Support\Config;
use ImWiki\Support\Logger;
use ImWiki\Support\Url;

$db=(array)Config::get('db',[]);$pdo=Connection::create($db);$prefix=(string)($db['prefix']??'');$users=new UserRepository($pdo,$prefix);$authz=new Authorization($pdo,$users,$prefix);
$uid=(int)($_SESSION['user_id']??0);
if($uid<=0||!$users->find($uid)){header('Location: '.Url::to('/login'));exit;}
if(!$authz->isAdmin($uid)){http_response_code(403);echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>403</title><link rel="stylesheet" href="'.Html::e(Url::to('/public/assets/app.css')).'"><main class="installer"><div class="card"><h1>Brak uprawnień</h1><p>Aktualizację bazy może uruchomić administrator systemu.</p></div></main></html>';exit;}

$migrator=new Migrator($pdo,__DIR__.'/database/migrations',$prefix);$pending=$migrator->pending();$error='';$ran=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::validate((string)($_POST['_csrf']??''))){http_response_code(419);$error='Sesja formularza wygasła. Odśwież stronę.';}
    else{
        try{$ran=$migrator->migrate();$pending=$migrator->pending();}
        catch(Throwable $e){(new Logger(__DIR__.'/storage/logs',false))->error('Database upgrade failed',['request_id'=>IMWIKI_REQUEST_ID,'exception'=>get_class($e),'message'=>$e->getMessage()]);$error='Aktualizacja bazy nie została zakończona. Sprawdź log aplikacji, używając identyfikatora żądania: '.IMWIKI_REQUEST_ID;}
    }
}
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aktualizacja bazy · imWiki</title><link rel="stylesheet" href="<?=Html::e(Url::to('/public/assets/app.css'))?>"></head><body><main class="installer"><div class="card"><h1>Aktualizacja bazy danych</h1><?php if($error):?><div class="alert danger"><?=Html::e($error)?></div><?php endif;?><?php if($ran):?><div class="alert success">Wykonano migracje: <?=Html::e(implode(', ',$ran))?></div><?php endif;?><?php if($pending):?><p>Kod aplikacji wymaga aktualizacji schematu. Migracje zostaną wykonane kolejno i oznaczone jako zakończone dopiero po powodzeniu.</p><ul><?php foreach($pending as $name):?><li><?=Html::e($name)?></li><?php endforeach;?></ul><form method="post"><?=Csrf::field()?><button class="button">Uruchom aktualizację bazy</button></form><?php else:?><div class="alert success">Schemat bazy jest aktualny.</div><a class="button" href="<?=Html::e(Url::to('/dashboard'))?>">Przejdź do imWiki</a><?php endif;?><p class="muted">Reference: <?=Html::e(IMWIKI_REQUEST_ID)?></p></div></main></body></html>
