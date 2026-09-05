<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\MailService;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class MailController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly MailService $mail,private readonly JobQueueService $jobs){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
    public function index(Request $request):void{$uid=$this->requireAuth();if(!$this->authz->isAdmin($uid)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;} $settings=$this->mail->settings();$settings['password']='';echo $this->view->render('admin/mail.php',$this->common(['settings'=>$settings,'notice'=>$_SESSION['mail_notice']??'','error'=>$_SESSION['mail_error']??'']));unset($_SESSION['mail_notice'],$_SESSION['mail_error']);}
    public function save(Request $request):void{$uid=$this->requireAuth();if(!$this->authz->isAdmin($uid)){http_response_code(403);return;}$this->csrf($request);$host=trim((string)$request->input('host'));$from=mb_strtolower(trim((string)$request->input('from_address')));if($host===''||!filter_var($from,FILTER_VALIDATE_EMAIL)){$_SESSION['mail_error']='Podaj host SMTP i poprawny adres nadawcy.';Response::redirect(Url::to('/admin/mail'));}
        $this->mail->save(['host'=>$host,'port'=>(int)$request->input('port',587),'username'=>(string)$request->input('username'),'password'=>(string)$request->input('password'),'encryption'=>(string)$request->input('encryption','tls'),'from_address'=>$from,'from_name'=>(string)$request->input('from_name','imWiki')]);$this->audit($request,'mail.settings_updated','settings',null,'Zmieniono konfigurację SMTP','security','warning');$_SESSION['mail_notice']='Konfiguracja SMTP została zapisana.';Response::redirect(Url::to('/admin/mail'));}
    public function test(Request $request):void{$uid=$this->requireAuth();if(!$this->authz->isAdmin($uid)){http_response_code(403);return;}$this->csrf($request);$to=mb_strtolower(trim((string)$request->input('to')));if(!filter_var($to,FILTER_VALIDATE_EMAIL)){$_SESSION['mail_error']='Podaj poprawny adres odbiorcy.';Response::redirect(Url::to('/admin/mail'));}try{$this->jobs->enqueue('email',['to'=>$to,'subject'=>'Test SMTP imWiki','html'=>'<p>Konfiguracja poczty imWiki działa poprawnie.</p>','text'=>'Konfiguracja poczty imWiki działa poprawnie.']);$_SESSION['mail_notice']='Wiadomość testowa została dodana do kolejki.';}catch(Throwable){$_SESSION['mail_error']='Nie udało się dodać wiadomości testowej do kolejki.';}Response::redirect(Url::to('/admin/mail'));}
}
