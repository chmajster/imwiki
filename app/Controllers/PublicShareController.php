<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PublicShareService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class PublicShareController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly PageRepository $pages,private readonly PublicShareService $shares){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function manage(Request $request,array $params):void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canManagePageRestrictions($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        echo $this->view->render('pages/public_shares.php',$this->common(['page'=>$page,'shares'=>$this->shares->listForPage((int)$page['id']),'publicEnabled'=>$this->shares->enabled(),'newShare'=>$_SESSION['new_public_share']??null]));
        unset($_SESSION['new_public_share']);
    }

    public function create(Request $request,array $params):void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canManagePageRestrictions($uid,$page)){http_response_code(403);return;}
        try{
            $share=$this->shares->create((int)$page['id'],$uid,trim((string)$request->input('expires_at'))?:null,(string)$request->input('password'));
            $share['url']=Url::to('/share/'.$share['token']);$_SESSION['new_public_share']=$share;
            $this->audit($request,'share.public_created','page',(int)$page['id'],'Utworzono publiczny link do strony','security','warning',['share_id'=>$share['id'],'expires_at'=>$share['expires_at']]);
        }catch(Throwable){$_SESSION['new_public_share']=['error'=>'Nie udało się utworzyć publicznego linku. Sprawdź, czy administrator włączył Public sharing.'];}
        Response::redirect(Url::to('/pages/'.$page['id'].'/public-shares'));
    }

    public function revoke(Request $request,array $params):void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canManagePageRestrictions($uid,$page)){http_response_code(403);return;}
        $this->shares->revoke((int)$params['shareId'],(int)$page['id']);
        $this->audit($request,'share.public_revoked','page',(int)$page['id'],'Unieważniono publiczny link','security','info',['share_id'=>(int)$params['shareId']]);
        Response::redirect(Url::to('/pages/'.$page['id'].'/public-shares'));
    }

    public function view(Request $request,array $params):void
    {
        $token=(string)$params['token'];$share=$this->shares->resolve($token);
        if(!$share){http_response_code(404);echo $this->view->render('public/not_found.php',['requestId'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'']);return;}
        $key='public_share_ok_'.(int)$share['id'];$protected=(string)($share['password_hash']??'')!=='';
        $error='';
        if($protected&&!($_SESSION[$key]??false)){
            if($request->method()==='POST'){
                $this->csrf($request);
                if($this->shares->verifyPassword($share,(string)$request->input('password'))){$_SESSION[$key]=true;Response::redirect(Url::to('/share/'.$token));}
                $error='Nieprawidłowe hasło.';
            }
            echo $this->view->render('public/password.php',['share'=>$share,'token'=>$token,'error'=>$error,'requestId'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'']);return;
        }
        $this->shares->touch((int)$share['id']);
        echo $this->view->render('public/page.php',['share'=>$share,'requestId'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'']);
    }

    public function adminSetting(Request $request):void
    {
        $uid=$this->requireAuth();if(!$this->authz->isAdmin($uid)){http_response_code(403);return;}$this->csrf($request);
        $enabled=(string)$request->input('enabled','0')==='1';$this->shares->setEnabled($enabled);
        $this->audit($request,'settings.public_sharing','settings',null,'Zmieniono ustawienie Public sharing','security','warning',['enabled'=>$enabled]);
        Response::redirect(Url::to('/admin/system'));
    }
}
