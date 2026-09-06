<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Http\Response;use ImWiki\Repositories\PageRepository;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\NotificationService;use ImWiki\Services\SavedSearchService;use ImWiki\Services\SearchService;use ImWiki\Support\Url;use ImWiki\View\View;use PDO;
final class SearchController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly SearchService $search,private readonly SavedSearchService $saved,private readonly PageRepository $pages){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function index(Request $r):void{$uid=$this->requireAuth();$q=trim((string)$r->input('q',''));$results=$q!==''?$this->search->searchVisible($q,$uid,75):[];$attachments=[];$engine=$this->search->engine();if($q!==''&&$engine instanceof \ImWiki\Search\MySqlSearchEngine)$attachments=$engine->attachmentSearch($this->search->parse($q)['text'],$uid,20);echo$this->view->render('search.php',$this->common(['query'=>$q,'results'=>$results,'attachments'=>$attachments,'parsed'=>$this->search->parse($q),'savedSearches'=>$this->saved->all($uid)]));}
 public function save(Request $r):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->saved->save($uid,(string)$r->input('name'),(string)$r->input('query'));Response::redirect(Url::to('/search?q='.rawurlencode((string)$r->input('query'))));}catch(\Throwable){Response::redirect(Url::to('/search?q='.rawurlencode((string)$r->input('query')).'&save=error'));}}
 public function remove(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);$this->saved->remove($uid,(int)$p['id']);Response::redirect(Url::to('/search'));}
}
