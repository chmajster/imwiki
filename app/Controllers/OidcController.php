<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Auth\ExternalIdentityService;
use ImWiki\Auth\OidcService;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\AuthService;
use ImWiki\Services\SessionService;
use ImWiki\Services\TotpService;
use ImWiki\Support\FeatureFlags;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class OidcController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,private readonly OidcService $oidc,private readonly ExternalIdentityService $identities,private readonly AuthService $authService,private readonly SessionService $sessions,private readonly TotpService $totp,private readonly FeatureFlags $flags){parent::__construct($pdo,$prefix,$view,$users,$authz,null);}

    public function start(Request $request,array $params):never
    {
        if($this->userId()>0)Response::redirect(Url::to('/dashboard'));
        if(!$this->flags->enabled('oidc'))Response::redirect(Url::to('/login?error=oidc_disabled'));
        $key=(string)($params['key']??'');
        $redirect=$this->callbackUrl($key);
        try{Response::redirect($this->oidc->authorizationUrl($key,$redirect));}
        catch(\Throwable){Response::redirect(Url::to('/login?error=oidc_start'));}
    }

    public function callback(Request $request,array $params):never
    {
        if(!$this->flags->enabled('oidc'))Response::redirect(Url::to('/login?error=oidc_disabled'));
        $key=(string)($params['key']??'');
        try{
            $claims=$this->oidc->callback($key,$request->all(),$this->callbackUrl($key));
            $user=$this->identities->resolveOrProvision($key,$claims);
            $uid=(int)$user['id'];
            if($this->totp->enabled($uid)){
                session_regenerate_id(true);
                $_SESSION['pending_2fa_user_id']=$uid;
                $_SESSION['pending_2fa_started_at']=time();
                $_SESSION['pending_external_provider']=$key;
                Response::redirect(Url::to('/login/2fa'));
            }
            $this->authService->loginUser($user);
            $this->sessions->start($uid,$request->ip(),$request->userAgent());
            $this->sessions->recordLogin($uid,(string)$user['username'],$request->ip(),$request->userAgent(),true);
            $this->audit($request,'auth.oidc_login','user',$uid,'Poprawne logowanie przez OIDC','security','info',['provider'=>$key]);
            Response::redirect(Url::to('/dashboard'));
        }catch(\Throwable){
            $this->audit($request,'auth.oidc_failed','auth_provider',null,'Nieudane logowanie OIDC','security','warning',['provider'=>$key]);
            Response::redirect(Url::to('/login?error=oidc_failed'));
        }
    }

    private function callbackUrl(string $key):string
    {
        $base=rtrim((string)\ImWiki\Support\Config::get('app.url',''),'/');
        if($base==='')throw new \RuntimeException('Application URL is not configured.');
        return $base.'/auth/oidc/'.rawurlencode($key).'/callback';
    }
}
