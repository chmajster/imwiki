<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\Crypto;
use ImWiki\Security\RateLimiter;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\MailService;
use ImWiki\Services\PasswordResetService;
use ImWiki\Support\Config;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class PasswordResetController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,private readonly PasswordResetService $resets,private readonly MailService $mail,private readonly JobQueueService $jobs,private readonly Crypto $crypto,private readonly RateLimiter $limiter){parent::__construct($pdo,$prefix,$view,$users,$authz);}
    public function requestForm(Request $request):void{echo $this->view->render('auth/forgot.php',$this->common(['sent'=>false,'error'=>'']));}
    public function requestReset(Request $request):void
    {
        $this->csrf($request);$identifier=trim((string)$request->input('login'));if($this->limiter->tooManyAttempts('reset:'.$request->ip().':'.mb_strtolower($identifier),5,3600)){echo $this->view->render('auth/forgot.php',$this->common(['sent'=>true,'error'=>'']));return;}
        try{$issued=$this->resets->issue($identifier);if($issued&&$this->mail->configured()){$base=rtrim((string)Config::get('app.url',''),'/');$url=$base.Url::to('/password-reset?token='.rawurlencode($issued['token']));$payload=['to'=>$issued['email'],'subject'=>'Reset hasła imWiki','html'=>'<p>Otrzymaliśmy prośbę o zmianę hasła. Link jest ważny przez 60 minut.</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">Ustaw nowe hasło</a></p><p>Jeżeli to nie Ty, zignoruj tę wiadomość.</p>','text'=>"Otrzymaliśmy prośbę o zmianę hasła. Link jest ważny przez 60 minut:\n{$url}\n\nJeżeli to nie Ty, zignoruj tę wiadomość."];$this->jobs->enqueue('encrypted_email',['envelope'=>$this->crypto->encrypt(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))]);}}
        catch(Throwable){}
        echo $this->view->render('auth/forgot.php',$this->common(['sent'=>true,'error'=>'']));
    }
    public function resetForm(Request $request):void{$token=(string)$request->input('token','');$valid=$this->resets->validate($token)!==null;echo $this->view->render('auth/reset.php',$this->common(['token'=>$token,'valid'=>$valid,'error'=>'','done'=>false]));}
    public function reset(Request $request):void
    {
        $this->csrf($request);$token=(string)$request->input('token');$password=(string)$request->input('password');$repeat=(string)$request->input('password_repeat');if($password!==$repeat){echo $this->view->render('auth/reset.php',$this->common(['token'=>$token,'valid'=>true,'error'=>'Hasła nie są identyczne.','done'=>false]));return;}
        try{$uid=$this->resets->reset($token,$password);$this->audit($request,'auth.password_reset','user',$uid,'Zresetowano hasło przez token jednorazowy','security','warning');echo $this->view->render('auth/reset.php',$this->common(['token'=>'','valid'=>false,'error'=>'','done'=>true]));}catch(Throwable){echo $this->view->render('auth/reset.php',$this->common(['token'=>$token,'valid'=>false,'error'=>'Link resetu jest nieważny, wygasł albo hasło nie spełnia wymagań.','done'=>false]));}
    }
}
