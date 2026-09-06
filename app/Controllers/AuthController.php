<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Auth\AuthenticationManager;
use ImWiki\Auth\ExternalIdentityService;
use ImWiki\Auth\OidcService;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\RateLimiter;
use ImWiki\Services\AuthService;
use ImWiki\Services\SessionService;
use ImWiki\Services\TotpService;
use ImWiki\Support\FeatureFlags;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class AuthController extends BaseController
{
    public function __construct(
        PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,
        private readonly AuthService $authService,
        private readonly RateLimiter $limiter,
        private readonly TotpService $totp,
        private readonly SessionService $sessions,
        private readonly ?AuthenticationManager $authenticationManager=null,
        private readonly ?ExternalIdentityService $externalIdentities=null,
        private readonly ?OidcService $oidc=null,
        private readonly ?FeatureFlags $flags=null,
    ){parent::__construct($pdo,$prefix,$view,$users,$authz);}

    public function loginForm(Request $request):void
    {
        if($this->userId())Response::redirect(Url::to('/dashboard'));
        if(isset($_SESSION['pending_2fa_user_id']))Response::redirect(Url::to('/login/2fa'));
        echo $this->view->render('auth/login.php',$this->common([
            'error'=>$this->loginError((string)$request->input('error','')),
            'passwordProviders'=>$this->authenticationManager?->passwordProviders()??[['key'=>'local','name'=>'Local account','type'=>'local']],
            'oidcProviders'=>($this->oidc&&($this->flags?->enabled('oidc')??false))?$this->oidc->enabledProviders():[],
        ]));
    }

    public function login(Request $request):void
    {
        $this->csrf($request);$login=trim((string)$request->input('login'));$provider=trim((string)$request->input('provider','local'))?:'local';
        $limitKey='login:'.$provider.':'.$request->ip().':'.mb_strtolower($login);
        if($this->limiter->tooManyAttempts($limitKey,8,900)){$this->renderLogin('Zbyt wiele prób logowania. Spróbuj ponownie później.');return;}
        try{
            $identity=$this->authenticationManager?$this->authenticationManager->authenticate($provider,$login,(string)$request->input('password')):$this->authService->credentials($login,(string)$request->input('password'));
            $user=$identity;
            if($identity&&$provider!=='local'&&isset($identity['external_id'])){
                if(!$this->externalIdentities)throw new \RuntimeException('External identity resolver unavailable.');
                $user=$this->externalIdentities->resolveOrProvision($provider,$identity);
            }
        }catch(\Throwable){$user=null;}
        $ok=is_array($user)&&isset($user['id']);if(!$ok)$this->sessions->recordLogin(null,$login,$request->ip(),$request->userAgent(),false);
        $this->audit($request,$ok?'auth.password_ok':'auth.login_failed','user',$ok?(int)$user['id']:null,$ok?'Poprawne uwierzytelnienie':'Nieudana próba logowania','security',$ok?'info':'warning',['provider'=>$provider]);
        if(!$ok){$this->renderLogin('Nieprawidłowe dane logowania lub konto zewnętrzne nie jest dostępne.');return;}
        if($this->totp->enabled((int)$user['id'])){session_regenerate_id(true);$_SESSION['pending_2fa_user_id']=(int)$user['id'];$_SESSION['pending_2fa_started_at']=time();$_SESSION['pending_external_provider']=$provider==='local'?null:$provider;Response::redirect(Url::to('/login/2fa'));}
        $this->finishLogin($request,$user,$provider);
    }

    public function twoFactorForm(Request $request):void
    {
        $uid=(int)($_SESSION['pending_2fa_user_id']??0);$started=(int)($_SESSION['pending_2fa_started_at']??0);if($uid<=0||$started<=0||time()-$started>300){unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_started_at'],$_SESSION['pending_external_provider']);Response::redirect(Url::to('/login'));}
        echo $this->view->render('auth/2fa.php',$this->common(['error'=>'']));
    }

    public function twoFactorVerify(Request $request):void
    {
        $this->csrf($request);$uid=(int)($_SESSION['pending_2fa_user_id']??0);$started=(int)($_SESSION['pending_2fa_started_at']??0);
        if($uid<=0||$started<=0||time()-$started>300){unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_started_at'],$_SESSION['pending_external_provider']);Response::redirect(Url::to('/login'));}
        if($this->limiter->tooManyAttempts('2fa:'.$uid.':'.$request->ip(),8,300)){http_response_code(429);echo $this->view->render('auth/2fa.php',$this->common(['error'=>'Zbyt wiele prób kodu. Zaloguj się ponownie później.']));return;}
        $code=(string)$request->input('code');$ok=$this->totp->verifyUser($uid,$code)||$this->totp->consumeRecoveryCode($uid,$code);
        if(!$ok){$pendingUser=$this->users->find($uid);$this->sessions->recordLogin($uid,(string)($pendingUser['username']??''),$request->ip(),$request->userAgent(),false);$this->audit($request,'auth.2fa_failed','user',$uid,'Nieudana weryfikacja 2FA','security','warning');echo $this->view->render('auth/2fa.php',$this->common(['error'=>'Nieprawidłowy kod uwierzytelniający lub kod odzyskiwania.']));return;}
        $user=$this->users->find($uid);if(!$user)Response::redirect(Url::to('/login'));$provider=(string)($_SESSION['pending_external_provider']??'local');$this->finishLogin($request,$user,$provider,true);
    }

    public function logout(Request $request):void
    {
        $this->requireAuth();$this->csrf($request);$uid=$this->userId();$this->audit($request,'auth.logout','user',$uid,'Wylogowanie','security');$this->sessions->revokeCurrent($uid);$this->authService->logout();Response::redirect(Url::to('/login'));
    }

    private function finishLogin(Request $request,array $user,string $provider,bool $with2fa=false):never
    {
        $uid=(int)$user['id'];$this->authService->loginUser($user);$this->sessions->start($uid,$request->ip(),$request->userAgent());$this->sessions->recordLogin($uid,(string)$user['username'],$request->ip(),$request->userAgent(),true);unset($_SESSION['pending_external_provider']);$this->audit($request,'auth.login','user',$uid,$with2fa?'Poprawne logowanie z 2FA':'Poprawne logowanie','security','info',['provider'=>$provider]);Response::redirect(Url::to('/dashboard'));
    }

    private function renderLogin(string $error):void
    {
        echo $this->view->render('auth/login.php',$this->common(['error'=>$error,'passwordProviders'=>$this->authenticationManager?->passwordProviders()??[['key'=>'local','name'=>'Local account','type'=>'local']],'oidcProviders'=>($this->oidc&&($this->flags?->enabled('oidc')??false))?$this->oidc->enabledProviders():[]]));
    }

    private function loginError(string $code):string{return match($code){'oidc_disabled'=>'Logowanie OIDC jest wyłączone.','oidc_start'=>'Nie udało się rozpocząć logowania OIDC.','oidc_failed'=>'Logowanie OIDC nie powiodło się.',default=>''};}
}
