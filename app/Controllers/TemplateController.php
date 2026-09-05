<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Http\Response;use ImWiki\Repositories\SpaceRepository;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\NotificationService;use ImWiki\Services\TemplateService;use ImWiki\Support\Url;use ImWiki\View\View;use PDO;use Throwable;
final class TemplateController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly TemplateService $templates,private readonly SpaceRepository $spaces){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function index(Request $r):void{$uid=$this->requireAuth();echo $this->view->render('admin/templates.php',$this->common(['templatesList'=>$this->templates->allManageable($uid),'spaces'=>$this->spaces->allVisible($uid,$this->authz->isAdmin($uid))]));}
 public function create(Request $r):void{$uid=$this->requireAuth();$this->csrf($r);try{$sid=(int)$r->input('space_id',0);$id=$this->templates->create($uid,$sid>0?$sid:null,$r->all());$this->audit($r,'template.created','template',$id,'Utworzono szablon');Response::redirect(Url::to('/admin/templates/'.$id));}catch(Throwable){Response::redirect(Url::to('/admin/templates?error=1'));}}
 public function edit(Request $r,array $p):void{$uid=$this->requireAuth();$t=$this->templates->find((int)$p['id'],$uid);if(!$t){http_response_code(404);return;}echo $this->view->render('admin/template_form.php',$this->common(['template'=>$t]));}
 public function update(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->templates->update((int)$p['id'],$uid,$r->all());$this->audit($r,'template.updated','template',(int)$p['id'],'Zmieniono szablon');Response::redirect(Url::to('/admin/templates/'.$p['id'].'?saved=1'));}catch(Throwable){Response::redirect(Url::to('/admin/templates/'.$p['id'].'?error=1'));}}
 public function clone(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$id=$this->templates->cloneTemplate((int)$p['id'],$uid);Response::redirect(Url::to('/admin/templates/'.$id));}catch(Throwable){http_response_code(403);}}
 public function archive(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->templates->archive((int)$p['id'],$uid);Response::redirect(Url::to('/admin/templates'));}catch(Throwable){Response::redirect(Url::to('/admin/templates/'.$p['id'].'?error=1'));}}
}
