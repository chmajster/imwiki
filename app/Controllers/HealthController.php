<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\HealthService;
use ImWiki\Services\NotificationService;
use ImWiki\View\View;
use PDO;

final class HealthController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly HealthService $health){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function readiness(Request $request):never
    {
        $result=$this->health->readiness();
        Response::json(['status'=>$result['ready']?'ready':'not_ready','checks'=>$result['checks'],'request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null],$result['ready']?200:503);
    }

    public function detailed(Request $request):never
    {
        $uid=$this->requireAuth();
        if(!$this->authz->canAdmin($uid,'admin.system'))Response::json(['error'=>['code'=>'permission_denied','message'=>'Access denied','request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null]],403);
        Response::json($this->health->detailed());
    }
}
