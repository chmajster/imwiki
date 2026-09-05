<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Security\RateLimiter;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\View\View;
use PDO;
use ImWiki\Services\AuthService;
use ImWiki\Services\TotpService;
use ImWiki\Services\SessionService;
use ImWiki\Support\Url;

final class AuthController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,private readonly AuthService $authService,private readonly RateLimiter $limiter,private readonly TotpService $totp,private readonly SessionService $sessions){parent::__construct($pdo,$prefix,$view,$users,$authz);}

    public function loginForm(Request $request):void
    {
        if($this->userId())Response::redirect(Url::to('/dashboard'));if(isset($_SESSION['pending_2fa_user_id']))Response::redirect(Url::to('/login/2fa'));
        echo $this->view->render('auth/login.php',$this->common(['error'=>'']));
    }

    public function login(Request $request):void
    {
        $this->csrf($request);$login=trim((string)$request->input('login'));
        if($this->limiter->tooManyAttempts('login:'.$request->ip().':'.mb_strtolower($login),8,900)){http_response_code(429);echo $this->view->render('auth/login.php',$this->common(['error'=>'Zbyt wiele prób logowania. Spróbuj ponownie później.']));return;}
        $user=$this->authService->credentials($login,(string)$request->input('password'));$ok=$user!==null;if(!$ok)$this->sessions->recordLogin(null,$login,$request->ip(),$request->userAgent(),false);
        $this->audit($request,$ok?'auth.password_ok':'auth.login_failed','user',$ok?(int)$user['id']:null,$ok?'Poprawne hasło':'Nieudana próba logowania','security',$ok?'info':'warning');
        if(!$user){echo $this->view->render('auth/login.php',$this->common(['error'=>'Nieprawidłowe dane logowania.']));return;}
        if($this->totp->enabled((int)$user['id'])){session_regenerate_id(true);$_SESSION['pending_2fa_user_id']=(int)$user['id'];$_SESSION['pending_2fa_started_at']=time();Response::redirect(Url::to('/login/2fa'));}
        $this->authService->loginUser($user);$this->sessions->start((int)$user['id'],$request->ip(),$request->userAgent());$this->sessions->recordLogin((int)$user['id'],$login,$request->ip(),$request->userAgent(),true);$this->audit($request,'auth.login','user',(int)$user['id'],'Poprawne logowanie','security');Response::redirect(Url::to('/dashboard'));
    }

    public function twoFactorForm(Request $request):void
    {
        $uid=(int)($_SESSION['pending_2fa_user_id']??0);$started=(int)($_SESSION['pending_2fa_started_at']??0);if($uid<=0||$started<=0||time()-$started>300){unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_started_at']);Response::redirect(Url::to('/login'));}
        echo $this->view->render('auth/2fa.php',$this->common(['error'=>'']));
    }

    public function twoFactorVerify(Request $request):void
    {
        $this->csrf($request);$uid=(int)($_SESSION['pending_2fa_user_id']??0);$started=(int)($_SESSION['pending_2fa_started_at']??0);
        if($uid<=0||$started<=0||time()-$started>300){unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_started_at']);Response::redirect(Url::to('/login'));}
        if($this->limiter->tooManyAttempts('2fa:'.$uid.':'.$request->ip(),8,300)){http_response_code(429);echo $this->view->render('auth/2fa.php',$this->common(['error'=>'Zbyt wiele prób kodu. Zaloguj się ponownie później.']));return;}
        $code=(string)$request->input('code');$ok=$this->totp->verifyUser($uid,$code)||$this->totp->consumeRecoveryCode($uid,$code);
        if(!$ok){$pendingUser=$this->users->find($uid);$this->sessions->recordLogin($uid,(string)($pendingUser['username']??''),$request->ip(),$request->userAgent(),false);$this->audit($request,'auth.2fa_failed','user',$uid,'Nieudana weryfikacja 2FA','security','warning');echo $this->view->render('auth/2fa.php',$this->common(['error'=>'Nieprawidłowy kod uwierzytelniający lub kod odzyskiwania.']));return;}
        $user=$this->users->find($uid);if(!$user){Response::redirect(Url::to('/login'));}$this->authService->loginUser($user);$this->sessions->start($uid,$request->ip(),$request->userAgent());$this->sessions->recordLogin($uid,(string)$user['username'],$request->ip(),$request->userAgent(),true);$this->audit($request,'auth.login','user',$uid,'Poprawne logowanie z 2FA','security');Response::redirect(Url::to('/dashboard'));
    }

    public function logout(Request $request):void
    {
        $this->requireAuth();$this->csrf($request);$uid=$this->userId();$this->audit($request,'auth.logout','user',$uid,'Wylogowanie','security');$this->sessions->revokeCurrent($uid);$this->authService->logout();Response::redirect(Url::to('/login'));
    }
}
