<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Http\Response;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\ApiTokenService;use ImWiki\Services\NotificationService;use ImWiki\Support\Url;use ImWiki\View\View;use PDO;
final class ApiTokenController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly ApiTokenService $tokens){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function index(Request $request):void{$uid=$this->requireAuth();$shown=$_SESSION['new_api_token']??null;unset($_SESSION['new_api_token']);header('Cache-Control: no-store');echo $this->view->render('profile/api_tokens.php',$this->common(['tokens'=>$this->tokens->listForUser($uid),'newToken'=>$shown,'error'=>(string)$request->input('error',''),'scopeOptions'=>ApiTokenService::SCOPES]));}
 public function create(Request $request):void{$uid=$this->requireAuth();$this->csrf($request);$scopes=$_POST['scopes']??[];if(!is_array($scopes))$scopes=[];try{$_SESSION['new_api_token']=$this->tokens->create($uid,(string)$request->input('name'),array_map('strval',$scopes),trim((string)$request->input('expires_at'))?:null);$this->audit($request,'api_token.created','user',$uid,'Utworzono osobisty token API','security');Response::redirect(Url::to('/api-tokens'));}catch(\InvalidArgumentException){Response::redirect(Url::to('/api-tokens?error=invalid'));}}
 public function revoke(Request $request,array $params):void{$uid=$this->requireAuth();$this->csrf($request);$this->tokens->revoke($uid,(int)$params['id']);$this->audit($request,'api_token.revoked','user',$uid,'Unieważniono osobisty token API','security');Response::redirect(Url::to('/api-tokens'));}
}
