<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Exceptions\AuthorizationException;
use ImWiki\Exceptions\NotFoundException;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use PDO;

final class PermissionDiagnosticsService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Authorization $authz,private readonly UserRepository $users,private readonly PageRepository $pages){}

    public function diagnose(int $actorId,int $userId,int $pageId):array
    {
        if(!$this->authz->canAdmin($actorId,'admin.security'))throw new AuthorizationException();
        $user=$this->users->find($userId);$page=$this->pages->find($pageId);if(!$user||!$page)throw new NotFoundException();
        $roles=$this->users->roles($userId);$permissions=$this->users->permissions($userId);
        $g=$this->pdo->prepare("SELECT g.id,g.name,g.label,g.system FROM `{$this->prefix}groups` g JOIN `{$this->prefix}group_users` gu ON gu.group_id=g.id WHERE gu.user_id=? ORDER BY g.label");$g->execute([$userId]);$groups=$g->fetchAll()?:[];
        $spaceGrants=$this->grants('space_permissions','space_id',(int)$page['space_id'],$userId);
        $pageGrants=$this->grants('page_permissions','page_id',$pageId,$userId);
        return [
            'user'=>['id'=>$userId,'username'=>$user['username'],'status'=>$user['status']],
            'page'=>['id'=>$pageId,'title'=>$page['title'],'space_id'=>(int)$page['space_id'],'space_key'=>$page['space_key'],'restriction_mode'=>$page['restriction_mode'],'owner_id'=>(int)($page['owner_id']??0),'status'=>$page['status']],
            'roles'=>$roles,
            'global_permissions'=>$permissions,
            'groups'=>$groups,
            'space_grants'=>$spaceGrants,
            'page_grants'=>$pageGrants,
            'effective'=>[
                'view'=>$this->authz->canViewPage($userId,$page),
                'edit'=>$this->authz->canEditPage($userId,$page),
                'delete'=>$this->authz->canDeletePage($userId,$page),
                'comment'=>$this->authz->canCommentPage($userId,$page),
                'attachment'=>$this->authz->canAttachPage($userId,$page),
                'manage_restrictions'=>$this->authz->canManagePageRestrictions($userId,$page),
            ],
            'inheritance'=>[
                ['level'=>'Global','source'=>$roles?'Role/RBAC: '.implode(', ',$roles):'No global role grant'],
                ['level'=>'Space','source'=>$spaceGrants?'Direct/group Space ACL':'No matching Space ACL row'],
                ['level'=>'Page','source'=>$page['restriction_mode']==='inherited'?'Inherited from Space':'Restriction mode: '.$page['restriction_mode']],
            ],
        ];
    }

    private function grants(string $table,string $resourceColumn,int $resourceId,int $userId):array
    {
        $sql="SELECT p.* FROM `{$this->prefix}{$table}` p WHERE p.{$resourceColumn}=? AND ((p.subject_type='user' AND p.subject_id=?) OR (p.subject_type='group' AND p.subject_id IN (SELECT group_id FROM `{$this->prefix}group_users` WHERE user_id=?))) ORDER BY p.subject_type,p.subject_id";
        $s=$this->pdo->prepare($sql);$s->execute([$resourceId,$userId,$userId]);return$s->fetchAll()?:[];
    }
}
