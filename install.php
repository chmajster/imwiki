<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Services\InstallerService;
use ImWiki\Support\Url;

$installer=new InstallerService(__DIR__);$lock=__DIR__.'/storage/installed.lock';$config=__DIR__.'/config/config.php';
if(is_file($lock)||is_file($config)){
    http_response_code(409);$complete=is_file($lock)&&is_file($config);
    $title=$complete?'imWiki jest już zainstalowana.':'Wykryto istniejącą lub niepełną instalację.';
    $message=$complete?'Ponowna instalacja z publicznego instalatora jest zablokowana.':'Automatyczna reinstalacja jest zablokowana, aby nie nadpisać istniejącej konfiguracji lub danych.';
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>imWiki</title><link rel="stylesheet" href="public/assets/app.css"><main class="installer"><div class="card"><h1>'.Html::e($title).'</h1><p>'.Html::e($message).'</p><a class="button" href="'.Html::e(Url::to('/')).'">Otwórz imWiki</a></div></main></html>';exit;
}

$step=max(1,min(6,(int)($_GET['step']??1)));$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::validate($_POST['_csrf']??null)){http_response_code(419);$error='Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';}
    elseif(isset($_POST['db_step'])){
        $db=['host'=>trim((string)$_POST['host']),'port'=>(int)($_POST['port']?:3306),'database'=>trim((string)$_POST['database']),'username'=>trim((string)$_POST['username']),'password'=>(string)$_POST['password'],'prefix'=>preg_replace('/[^a-zA-Z0-9_]/','',(string)($_POST['prefix']??''))?:''];
        [$ok,$message]=$installer->testDatabase($db);$step=2;
        if($ok){$_SESSION['install_db']=$db;$notice=$message;if(!isset($_POST['test_only'])){header('Location: ?step=3');exit;}}else{$error=$message;}
    }
    elseif(isset($_POST['site_settings'])){
        $app=['site_name'=>trim((string)$_POST['site_name']),'app_url'=>rtrim(trim((string)$_POST['app_url']),'/'),'language'=>in_array((string)$_POST['language'],['pl','en'],true)?(string)$_POST['language']:'pl','timezone'=>trim((string)$_POST['timezone'])];
        $validUrl=filter_var($app['app_url'],FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($app['app_url'],PHP_URL_SCHEME)),['http','https'],true);
        if($app['site_name']===''||!$validUrl||$app['timezone']===''||!in_array($app['timezone'],timezone_identifiers_list(),true)){$error='Sprawdź nazwę strony, URL i strefę czasową.';$step=3;}
        elseif(!isset($_SESSION['install_db'])){$error='Najpierw skonfiguruj połączenie z bazą danych.';$step=2;}
        else{$_SESSION['install_app']=$app;header('Location: ?step=4');exit;}
    }
    elseif(isset($_POST['administrator'])){
        $admin=['email'=>mb_strtolower(trim((string)$_POST['email'])),'username'=>trim((string)$_POST['admin_username']),'first_name'=>trim((string)$_POST['first_name']),'last_name'=>trim((string)$_POST['last_name']),'password'=>(string)$_POST['admin_password']];
        $validUsername=(bool)preg_match('/^[A-Za-z0-9._-]{2,100}$/',$admin['username']);
        if(!filter_var($admin['email'],FILTER_VALIDATE_EMAIL)||!$validUsername||$admin['first_name']===''||$admin['last_name']===''||mb_strlen($admin['password'])<10||$admin['password']!==($_POST['admin_password_repeat']??'')){$error='Sprawdź dane administratora. Login może zawierać litery, cyfry, kropkę, myślnik i podkreślenie. Hasło musi mieć co najmniej 10 znaków i oba hasła muszą być identyczne.';$step=4;}
        elseif(!isset($_SESSION['install_app'],$_SESSION['install_db'])){$error='Najpierw skonfiguruj bazę i ustawienia strony.';$step=2;}
        else{$_SESSION['install_admin']=$admin;header('Location: ?step=5');exit;}
    }
    elseif(isset($_POST['perform_install'])){
        $step=5;
        try{
            if(!$installer->requirementsPass())throw new RuntimeException('Serwer nie spełnia wymagań krytycznych.');
            if(!isset($_SESSION['install_db'],$_SESSION['install_app'],$_SESSION['install_admin']))throw new RuntimeException('Brakuje danych wcześniejszych etapów instalacji.');
            $installer->install($_SESSION['install_db'],$_SESSION['install_app'],$_SESSION['install_admin']);
            unset($_SESSION['install_db'],$_SESSION['install_app'],$_SESSION['install_admin']);$_SESSION['install_complete']=true;
            header('Location: ?step=6');exit;
        }catch(Throwable $e){$error='Instalacja nie została zakończona. Możesz poprawić konfigurację i ponowić próbę. Szczegóły techniczne zapisano w logu instalatora.';@file_put_contents(__DIR__.'/storage/logs/install.log','['.gmdate('c').'] request='.(defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'-').' '.get_class($e).': '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);}
    }
}

$checks=$installer->requirements();$requirementsPass=$installer->requirementsPass();$detectedUrl=Url::currentAppUrl();
$labels=[1=>'Wymagania',2=>'Baza danych',3=>'Strona',4=>'Administrator',5=>'Instalacja',6=>'Gotowe'];
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalacja imWiki</title><link rel="stylesheet" href="public/assets/app.css"></head><body class="install-body"><main class="installer"><div class="install-brand"><strong>imWiki</strong><span>v<?=Html::e(IMWIKI_VERSION)?></span></div><ol class="installer-steps" aria-label="Etapy instalacji"><?php foreach($labels as $number=>$label):?><li class="<?=$number===$step?'active':($number<$step?'done':'')?>"><span><?=$number?></span><?=Html::e($label)?></li><?php endforeach;?></ol><section class="card install-card">
<?php if($error):?><div class="alert danger"><?=Html::e($error)?></div><?php endif;?><?php if($notice):?><div class="alert success"><?=Html::e($notice)?></div><?php endif;?>
<?php if($step===1):?><p class="eyebrow">Krok 1 z 6</p><h1>Wymagania serwera</h1><p>imWiki instaluje się w całości przez przeglądarkę. Nie potrzebujesz SSH, Composera, npm ani ręcznego SQL.</p><div class="checks"><?php foreach($checks as [$name,$ok,$critical,$detail]):?><div class="check"><span><?=Html::e($name)?></span><span class="badge <?=$ok?'ok':($critical?'bad':'warn')?>"><?=Html::e($detail)?></span></div><?php endforeach;?></div><div class="actions"><a class="button secondary" href="?step=1">Ponów test</a><?php if($requirementsPass):?><a class="button" href="?step=2">Dalej</a><?php endif;?></div><?php if(!$requirementsPass):?><p class="muted">Usuń błędy krytyczne. Ostrzeżenia nie blokują instalacji.</p><?php endif;?>
<?php elseif($step===2):?><p class="eyebrow">Krok 2 z 6</p><h1>Baza danych</h1><form method="post"><?=Csrf::field()?><div class="grid"><label>Host<input name="host" value="<?=Html::e($_SESSION['install_db']['host']??'localhost')?>" required></label><label>Port<input name="port" type="number" min="1" max="65535" value="<?=Html::e($_SESSION['install_db']['port']??3306)?>" required></label><label>Nazwa bazy<input name="database" value="<?=Html::e($_SESSION['install_db']['database']??'')?>" required></label><label>Użytkownik<input name="username" value="<?=Html::e($_SESSION['install_db']['username']??'')?>" required></label><label>Hasło<input name="password" type="password" autocomplete="new-password"></label><label>Prefix tabel<input name="prefix" value="<?=Html::e($_SESSION['install_db']['prefix']??'')?>" pattern="[A-Za-z0-9_]*"></label></div><input type="hidden" name="db_step" value="1"><div class="actions"><button class="button secondary" name="test_only" value="1">Testuj połączenie</button><button class="button">Zapisz i dalej</button></div></form>
<?php elseif($step===3):?><p class="eyebrow">Krok 3 z 6</p><h1>Ustawienia strony</h1><form method="post"><?=Csrf::field()?><input type="hidden" name="site_settings" value="1"><div class="grid"><label>Nazwa strony<input name="site_name" value="<?=Html::e($_SESSION['install_app']['site_name']??'imWiki')?>" required></label><label>URL aplikacji<input name="app_url" type="url" value="<?=Html::e($_SESSION['install_app']['app_url']??$detectedUrl)?>" required><small>Sprawdź wykryty adres. Nie jest bezwarunkowo przyjmowany z nagłówka Host.</small></label><label>Język<select name="language"><option value="pl">Polski</option><option value="en" <?=($_SESSION['install_app']['language']??'')==='en'?'selected':''?>>English</option></select></label><label>Strefa czasowa<input name="timezone" value="<?=Html::e($_SESSION['install_app']['timezone']??'Europe/Warsaw')?>" required></label></div><div class="actions"><a class="button secondary" href="?step=2">Wstecz</a><button class="button">Dalej</button></div></form>
<?php elseif($step===4):?><p class="eyebrow">Krok 4 z 6</p><h1>Konto administratora</h1><form method="post"><?=Csrf::field()?><input type="hidden" name="administrator" value="1"><div class="grid"><label>E-mail<input name="email" type="email" value="<?=Html::e($_SESSION['install_admin']['email']??'')?>" required></label><label>Login<input name="admin_username" pattern="[A-Za-z0-9._-]{2,100}" value="<?=Html::e($_SESSION['install_admin']['username']??'')?>" required></label><label>Imię<input name="first_name" value="<?=Html::e($_SESSION['install_admin']['first_name']??'')?>" required></label><label>Nazwisko<input name="last_name" value="<?=Html::e($_SESSION['install_admin']['last_name']??'')?>" required></label><label>Hasło<input name="admin_password" type="password" minlength="10" autocomplete="new-password" required></label><label>Powtórz hasło<input name="admin_password_repeat" type="password" minlength="10" autocomplete="new-password" required></label></div><div class="actions"><a class="button secondary" href="?step=3">Wstecz</a><button class="button">Dalej</button></div></form>
<?php elseif($step===5):?><p class="eyebrow">Krok 5 z 6</p><h1>Instalacja</h1><p>Instalator utworzy schemat i migracje, role i grupy, administratora, ustawienia, Space <b>WELCOME</b>, stronę powitalną, konfigurację produkcyjną oraz blokadę ponownej instalacji.</p><ul class="install-summary"><li>Baza: <?=Html::e((string)($_SESSION['install_db']['host']??'?'))?> / <?=Html::e((string)($_SESSION['install_db']['database']??'?'))?></li><li>Strona: <?=Html::e((string)($_SESSION['install_app']['site_name']??'?'))?></li><li>Administrator: <?=Html::e((string)($_SESSION['install_admin']['username']??'?'))?></li></ul><form method="post"><?=Csrf::field()?><button class="button" name="perform_install" value="1">Zainstaluj imWiki</button></form>
<?php else:?><p class="eyebrow">Krok 6 z 6</p><h1>imWiki została poprawnie zainstalowana.</h1><p>Konfiguracja i baza są gotowe. Tryb debugowania jest wyłączony, a publiczny instalator został zablokowany.</p><div class="actions"><a class="button" href="<?=Html::e(Url::to('/login'))?>">Przejdź do logowania</a><a class="button secondary" href="<?=Html::e(Url::to('/'))?>">Otwórz imWiki</a></div><?php endif;?>
</section><p class="muted install-reference">Reference: <?=Html::e(defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'-')?></p></main></body></html>
