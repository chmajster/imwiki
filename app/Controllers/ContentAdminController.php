<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\ContentHealthService;use ImWiki\Services\NotificationService;use ImWiki\View\View;use PDO;
final class ContentAdminController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly ContentHealthService $health){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function index(Request $r):void{$uid=$this->requireAuth();if(!$this->authz->can($uid,'administration.access')){http_response_code(403);return;}$days=max(1,(int)$r->input('days',180));echo $this->view->render('admin/content.php',$this->common(['broken'=>$this->health->brokenLinks(),'orphans'=>$this->health->orphanPages(),'stale'=>$this->health->stale($days),'days'=>$days]));}
}
