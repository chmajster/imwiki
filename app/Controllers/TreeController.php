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
use ImWiki\View\View;
use PDO;

final class TreeController extends BaseController
{
    public function __construct(
        PDO $pdo,
        string $prefix,
        View $view,
        UserRepository $users,
        Authorization $authz,
        ?NotificationService $notifications,
        private readonly SpaceRepository $spaces,
        private readonly PageRepository $pages,
    ) {
        parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);
    }

    public function children(Request $request,array $params):never
    {
        $uid=$this->requireAuth();
        $space=$this->spaces->findByKey((string)$params['key']);
        if(!$space||!$this->authz->canViewSpace($uid,(int)$space['id']))Response::json(['error'=>'not_found'],404);
        $parent=(int)$request->input('parent_id',0);$limit=max(1,min(100,(int)$request->input('limit',50)));$offset=max(0,(int)$request->input('offset',0));
        if($parent>0){$page=$this->pages->find($parent);if(!$page||(int)$page['space_id']!==(int)$space['id']||!$this->authz->canViewPage($uid,$page))Response::json(['error'=>'not_found'],404);}
        $items=$this->pages->childrenVisible((int)$space['id'],$parent>0?$parent:null,$uid,$this->authz->isAdmin($uid),$limit+1,$offset);
        $hasMore=count($items)>$limit;if($hasMore)array_pop($items);
        Response::json(['items'=>array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'parent_id'=>$row['parent_id']!==null?(int)$row['parent_id']:null,'title'=>(string)$row['title'],'slug'=>(string)$row['slug'],'has_children'=>(bool)$row['has_children']],$items),'offset'=>$offset,'next_offset'=>$hasMore?$offset+$limit:null,'has_more'=>$hasMore]);
    }
}
