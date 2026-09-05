<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class NotificationController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications)
    {
        parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);
    }

    public function index(Request $request): void
    {
        $uid=$this->requireAuth();$page=max(1,(int)$request->input('page',1));
        $data=$this->notifications?->page($uid,$page,25) ?? ['items'=>[],'page'=>1,'has_previous'=>false,'has_next'=>false];
        echo $this->view->render('notifications/index.php',$this->common(['notificationsPage'=>$data]));
    }


    public function preferences(Request $request): void
    {
        $uid=$this->requireAuth();echo $this->view->render('profile/notifications.php',$this->common(['preferences'=>$this->notifications?->preferences($uid)??[]]));
    }

    public function savePreferences(Request $request): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$categories=$request->input('categories',[]);if(!is_array($categories))$categories=[];$this->notifications?->savePreferences($uid,(string)$request->input('in_app','1')==='1',(string)$request->input('email_mode','none'),$categories);Response::redirect(Url::to('/profile/notifications?saved=1'));
    }

    public function markRead(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$this->notifications?->markRead($uid,(int)$params['id']);
        $return=(string)$request->input('return','/notifications');if(!str_starts_with($return,'/'))$return='/notifications';
        Response::redirect(Url::to($return));
    }

    public function markAllRead(Request $request): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$this->notifications?->markAllRead($uid);Response::redirect(Url::to('/notifications'));
    }
}
