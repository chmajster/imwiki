<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Services\SessionService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class SessionController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly SessionService $sessions){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
    public function index(Request $request):void{$uid=$this->requireAuth();echo $this->view->render('profile/sessions.php',$this->common(['sessions'=>$this->sessions->listOwn($uid),'history'=>$this->sessions->history($uid,50)]));}
    public function revoke(Request $request,array $params):void{$uid=$this->requireAuth();$this->csrf($request);$this->sessions->revokeOwn($uid,(int)$params['id']);$this->audit($request,'security.session_revoked','user',$uid,'Unieważniono sesję','security','warning',['session_record_id'=>(int)$params['id']]);Response::redirect(Url::to('/profile/sessions'));}
    public function revokeOthers(Request $request):void{$uid=$this->requireAuth();$this->csrf($request);$this->sessions->revokeOthers($uid);$this->audit($request,'security.sessions_revoked','user',$uid,'Wylogowano pozostałe sesje','security','warning');Response::redirect(Url::to('/profile/sessions'));}
}
