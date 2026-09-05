<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Services\TaskService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class TaskController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly TaskService $tasks,private readonly PageRepository $pages,private readonly SpaceRepository $spaces)
    {parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function mine(Request $request): void
    {
        $uid=$this->requireAuth();$filter=(string)$request->input('filter','open');if(!in_array($filter,['open','done','overdue','all'],true))$filter='open';
        $space=(int)$request->input('space',0);$visibleSpaces=$this->spaces->allVisible($uid,$this->authz->isAdmin($uid));
        $allowedIds=array_map(static fn(array $s):int=>(int)$s['id'],$visibleSpaces);$spaceId=$space>0&&in_array($space,$allowedIds,true)?$space:null;
        echo $this->view->render('tasks/index.php',$this->common(['tasks'=>$this->tasks->mine($uid,$filter,$spaceId),'filter'=>$filter,'spaces'=>$visibleSpaces,'spaceId'=>$spaceId]));
    }

    public function create(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$pageId=(int)$params['id'];
        try{$taskId=$this->tasks->create($pageId,(string)$request->input('description'),trim((string)$request->input('assignee'))?:null,trim((string)$request->input('due_date'))?:null,$uid);$this->audit($request,'task.created','task',$taskId,'Utworzono zadanie');Response::redirect(Url::to('/pages/'.$pageId.'#tasks'));}
        catch(\Throwable){Response::redirect(Url::to('/pages/'.$pageId.'?task=error#tasks'));}
    }

    public function complete(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);
        try{$pageId=$this->tasks->setCompleted((int)$params['id'],(string)$request->input('completed','1')==='1',$uid);$this->audit($request,'task.updated','task',(int)$params['id'],'Zmieniono status zadania');Response::redirect(Url::to('/pages/'.$pageId.'#tasks'));}
        catch(\Throwable){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());}
    }
}
