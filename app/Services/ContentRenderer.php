<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Macros\CoreMacros;
use ImWiki\Macros\MacroRegistry;
use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class ContentRenderer
{
    private MacroRegistry $registry;
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz,?MacroRegistry $registry=null)
    {
        $this->registry=$registry??new MacroRegistry();if($registry===null)(new CoreMacros($pdo,$prefix,$pages,$authz))->register($this->registry);
    }

    public function render(array $page,int $userId):string
    {
        return$this->registry->render((string)$page['content'],['page'=>$page,'user_id'=>$userId,'authz'=>$this->authz,'can'=>fn(string $permission):bool=>$this->authz->can($userId,$permission)]);
    }

    public function registry():MacroRegistry{return$this->registry;}
}
