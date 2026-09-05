<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Database\Migrator;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Support\Cache;
use ImWiki\Support\Url;
use ImWiki\Services\RetentionService;
use ImWiki\Services\SchedulerService;

final class AdminOperationsController extends BaseController
{
    public function __construct(\PDO $pdo,string $prefix,\ImWiki\View\View $view,\ImWiki\Repositories\UserRepository $users,\ImWiki\Security\Authorization $authz,?\ImWiki\Services\NotificationService $notifications,private readonly RetentionService $retention,private readonly SchedulerService $scheduler,private readonly Cache $cache,private readonly string $root) { parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications); }
    private function admin(): int {$uid=$this->requireAuth();if(!$this->authz->can($uid,'administration.access')){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());exit;}return $uid;}

    public function logs(Request $request): void
    {
        $this->admin();$q=trim((string)$request->input('q',''));$level=strtoupper(trim((string)$request->input('level','')));$page=max(1,(int)$request->input('page',1));$per=100;$path=$this->root.'/storage/logs/imwiki.log';$rows=[];
        if(is_file($path)){$lines=@file($path,FILE_IGNORE_NEW_LINES)?:[];$lines=array_reverse(array_slice($lines,-10000));foreach($lines as $line){if($q!==''&&!str_contains(mb_strtolower($line),mb_strtolower($q)))continue;if($level!==''&&!preg_match('/\]\s+'.preg_quote($level,'/').'\s+/',$line))continue;$rows[]=$this->maskLog($line);}}
        $total=count($rows);$rows=array_slice($rows,($page-1)*$per,$per);echo $this->view->render('admin/logs.php',$this->common(['rows'=>$rows,'query'=>$q,'level'=>$level,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per))]));
    }

    public function retention(Request $request): void {$this->admin();echo $this->view->render('admin/retention.php',$this->common(['settings'=>$this->retention->settings()]));}
    public function saveRetention(Request $request): void {$this->admin();$this->csrf($request);$this->retention->save((array)$request->all());$this->audit($request,'retention.updated','setting',null,'Zmieniono politykę retencji','security');Response::redirect(Url::to('/admin/retention?saved=1'));}
    public function cleanup(Request $request): void {$this->admin();$this->csrf($request);$r=$this->scheduler->runNow();$this->audit($request,'maintenance.cleanup','system',null,'Uruchomiono cleanup','maintenance','info',$r);$_SESSION['cleanup_result']=$r;Response::redirect(Url::to('/admin/retention?cleanup=1'));}

    public function database(Request $request): void
    {
        $this->admin();$db=(array)\ImWiki\Support\Config::get('db',[]);$m=new Migrator($this->pdo,$this->root.'/database/migrations',$this->prefix);$tables=(int)$this->pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();echo $this->view->render('admin/database.php',$this->common(['dbVersion'=>(string)$this->pdo->query('SELECT VERSION()')->fetchColumn(),'prefix'=>$this->prefix,'database'=>(string)($db['database']??''),'pending'=>$m->pending(),'tables'=>$tables,'cache'=>$this->cache->status()]));
    }
    public function clearCache(Request $request): void {$this->admin();$this->csrf($request);$n=$this->cache->clear();$this->audit($request,'cache.cleared','system',null,'Wyczyszczono cache','maintenance','info',['entries'=>$n]);Response::redirect(Url::to('/admin/database?cache=cleared'));}

    private function maskLog(string $line): string
    {
        $line=preg_replace('/(?i)(password|token|secret|authorization|cookie|session[_-]?id)(["\'=:\s]+)([^,}\s]+)/','$1$2[REDACTED]',$line)??$line;
        $line=preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i','Bearer [REDACTED]',$line)??$line;
        return mb_substr($line,0,5000);
    }
}
