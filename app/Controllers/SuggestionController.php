<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Http\Response;use ImWiki\Repositories\PageRepository;use ImWiki\Repositories\SpaceRepository;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Security\RateLimiter;use ImWiki\Services\NotificationService;use ImWiki\Services\SavedSearchService;use ImWiki\Services\SearchService;use ImWiki\Support\Url;use ImWiki\View\View;use PDO;
final class SuggestionController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly RateLimiter $limiter,private readonly SearchService $search,private readonly SavedSearchService $saved,private readonly PageRepository $pages,private readonly SpaceRepository $spaces){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function search(Request $r):never{$uid=$this->requireAuth();if($this->limiter->tooManyAttempts('search-suggest:'.$uid,80,60))Response::json(['error'=>'rate_limited'],429);$q=trim((string)$r->input('q',''));if(mb_strlen($q)<1)Response::json(['items'=>[]]);$items=[];
  foreach($this->search->search($q,10) as $row){$page=$this->pages->find((int)$row['id']);if($page&&$this->authz->canViewPage($uid,$page)){$items[]=['kind'=>'page','title'=>$row['title'],'subtitle'=>$row['space_name'],'url'=>Url::to('/pages/'.$row['id'])];if(count($items)>=5)break;}}
  foreach($this->spaces->allVisible($uid,$this->authz->isAdmin($uid)) as $space){if(str_contains(mb_strtolower($space['name'].' '.$space['space_key']),mb_strtolower($q)))$items[]=['kind'=>'space','title'=>$space['name'],'subtitle'=>$space['space_key'],'url'=>Url::to('/spaces/'.$space['space_key'])];if(count($items)>=8)break;}
  $like=$q.'%';$stmt=$this->pdo->prepare("SELECT username,first_name,last_name FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY username LIMIT 3");$stmt->execute([$like,$like,$like]);foreach($stmt->fetchAll() as $u)$items[]=['kind'=>'user','title'=>'@'.$u['username'],'subtitle'=>trim($u['first_name'].' '.$u['last_name']),'url'=>Url::to('/search?q='.rawurlencode('author:'.$u['username']))];
  foreach($this->saved->all($uid) as $saved){if(str_contains(mb_strtolower($saved['name'].' '.$saved['query_text']),mb_strtolower($q)))$items[]=['kind'=>'saved','title'=>$saved['name'],'subtitle'=>$saved['query_text'],'url'=>Url::to('/search?q='.rawurlencode($saved['query_text']))];if(count($items)>=12)break;}
  Response::json(['items'=>array_slice($items,0,12)]);
 }
}
