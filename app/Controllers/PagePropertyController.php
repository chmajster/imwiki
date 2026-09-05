<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Http\Response;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\NotificationService;use ImWiki\Services\PagePropertyService;use ImWiki\Support\Url;use ImWiki\View\View;use PDO;
final class PagePropertyController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly PagePropertyService $properties){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function set(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->properties->set((int)$p['id'],(string)$r->input('key'),(string)$r->input('label'),(string)$r->input('type'),(string)$r->input('value'),(string)$r->input('options'),$uid);$this->audit($r,'page.property_set','page',(int)$p['id'],'Zmieniono właściwość strony','content');Response::redirect(Url::to('/pages/'.$p['id'].'#properties'));}catch(\Throwable){Response::redirect(Url::to('/pages/'.$p['id'].'?property=error#properties'));}}
 public function remove(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->properties->remove((int)$p['id'],(int)$p['propertyId'],$uid);$this->audit($r,'page.property_removed','page',(int)$p['id'],'Usunięto właściwość strony','content');Response::redirect(Url::to('/pages/'.$p['id'].'#properties'));}catch(\Throwable){http_response_code(403);}}
}
