<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PagePermissionService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class PageAccessController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly PageRepository $pages,private readonly PagePermissionService $permissions)
    {parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function show(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canManagePageRestrictions($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        echo $this->view->render('pages/restrictions.php',$this->common(['page'=>$page,'grants'=>$this->permissions->grants((int)$page['id']),'usersList'=>$this->permissions->users(),'groupsList'=>$this->permissions->groups()]));
    }

    public function setMode(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$pageId=(int)$params['id'];
        try{$this->permissions->setMode($pageId,(string)$request->input('mode'),$uid);$this->audit($request,'page.restrictions_mode','page',$pageId,'Zmieniono tryb ograniczeń strony','permissions');Response::redirect(Url::to('/pages/'.$pageId.'/restrictions'));}
        catch(\Throwable){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());}
    }

    public function grant(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$pageId=(int)$params['id'];
        try{$this->permissions->grant($pageId,(string)$request->input('subject_type'),(int)$request->input('subject_id'),(string)$request->input('access')==='edit',$uid);$this->audit($request,'page.permission_granted','page',$pageId,'Nadano uprawnienie do strony','permissions');Response::redirect(Url::to('/pages/'.$pageId.'/restrictions'));}
        catch(\Throwable){Response::redirect(Url::to('/pages/'.$pageId.'/restrictions?error=grant'));}
    }

    public function revoke(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$pageId=(int)$params['id'];
        try{$this->permissions->revoke($pageId,(int)$params['grantId'],$uid);$this->audit($request,'page.permission_revoked','page',$pageId,'Odebrano uprawnienie do strony','permissions');Response::redirect(Url::to('/pages/'.$pageId.'/restrictions'));}
        catch(\Throwable){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());}
    }
}
