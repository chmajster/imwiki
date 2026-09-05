<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Services\TotpService;
use ImWiki\Support\Config;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class SecurityController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly TotpService $totp){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function index(Request $request):void
    {
        $uid=$this->requireAuth();$user=$this->users->find($uid);$secret=$this->totp->pendingSecret($uid);$uri=$secret?$this->totp->provisioningUri((string)Config::get('app.name','imWiki'),(string)$user['email'],$secret):null;
        $codes=$_SESSION['recovery_codes_once']??null;unset($_SESSION['recovery_codes_once']);
        echo $this->view->render('profile/security.php',$this->common(['totpEnabled'=>$this->totp->enabled($uid),'pendingSecret'=>$secret,'provisioningUri'=>$uri,'recoveryCodes'=>$codes,'remainingRecovery'=>$this->totp->enabled($uid)?$this->totp->remainingRecoveryCodes($uid):0,'error'=>$_SESSION['security_error']??'','notice'=>$_SESSION['security_notice']??'']));
        unset($_SESSION['security_error'],$_SESSION['security_notice']);
    }

    public function begin2fa(Request $request):void
    {
        $uid=$this->requireAuth();$this->csrf($request);try{$this->totp->begin($uid);$this->audit($request,'security.2fa_setup_started','user',$uid,'Rozpoczęto konfigurację TOTP','security');}catch(Throwable){$_SESSION['security_error']='Nie udało się rozpocząć konfiguracji 2FA.';}Response::redirect(Url::to('/profile/security'));
    }

    public function confirm2fa(Request $request):void
    {
        $uid=$this->requireAuth();$this->csrf($request);try{$codes=$this->totp->confirm($uid,(string)$request->input('code'));$_SESSION['recovery_codes_once']=$codes;$_SESSION['security_notice']='2FA zostało aktywowane. Zapisz kody odzyskiwania — zostaną pokazane tylko teraz.';$this->audit($request,'security.2fa_enabled','user',$uid,'Aktywowano TOTP','security','warning');}catch(Throwable){$_SESSION['security_error']='Kod TOTP jest nieprawidłowy. Konfiguracja nie została aktywowana.';}Response::redirect(Url::to('/profile/security'));
    }

    public function disable2fa(Request $request):void
    {
        $uid=$this->requireAuth();$this->csrf($request);$user=$this->users->find($uid);if(!$user||!password_verify((string)$request->input('password'),(string)$user['password_hash'])){$_SESSION['security_error']='Podaj poprawne aktualne hasło, aby wyłączyć 2FA.';Response::redirect(Url::to('/profile/security'));}
        $this->totp->disable($uid);$this->audit($request,'security.2fa_disabled','user',$uid,'Wyłączono TOTP','security','warning');$_SESSION['security_notice']='2FA zostało wyłączone.';Response::redirect(Url::to('/profile/security'));
    }
}
