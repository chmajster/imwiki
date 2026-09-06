<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Auth\AuthenticationManager;
use ImWiki\Auth\OidcService;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Support\FeatureFlags;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class AuthenticationAdminController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly AuthenticationManager $manager,private readonly OidcService $oidc,private readonly FeatureFlags $flags){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function index(Request $request):void
    {
        $uid=$this->requireAdmin();
        echo $this->view->render('admin/authentication.php',$this->common([
            'providers'=>$this->manager->providers(),
            'oidcEnabled'=>$this->flags->enabled('oidc'),
            'message'=>(string)$request->input('message',''),
        ]));
    }

    public function saveLdap(Request $request):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);
        try{
            $key=mb_strtolower(trim((string)$request->input('provider_key','ldap')));
            $config=[
                'host'=>trim((string)$request->input('host','')),
                'port'=>(int)$request->input('port',636),
                'base_dn'=>trim((string)$request->input('base_dn','')),
                'bind_dn'=>trim((string)$request->input('bind_dn','')),
                'user_filter'=>trim((string)$request->input('user_filter','(uid={username})')),
                'tls_mode'=>in_array((string)$request->input('tls_mode'),['ldaps','starttls'],true)?(string)$request->input('tls_mode'):'ldaps',
                'timeout'=>max(2,min(15,(int)$request->input('timeout',5))),
                'id_attr'=>trim((string)$request->input('id_attr','entryuuid')),
                'username_attr'=>trim((string)$request->input('username_attr','uid')),
                'email_attr'=>trim((string)$request->input('email_attr','mail')),
                'first_name_attr'=>trim((string)$request->input('first_name_attr','givenname')),
                'last_name_attr'=>trim((string)$request->input('last_name_attr','sn')),
                'groups_attr'=>trim((string)$request->input('groups_attr','memberof')),
                'group_sync_mode'=>in_array((string)$request->input('group_sync_mode'),['off','create_missing','map_existing','sync'],true)?(string)$request->input('group_sync_mode'):'off',
                'claim_groups'=>'groups',
            ];
            $this->manager->saveLdap($key,trim((string)$request->input('display_name','LDAP')),$config,(string)$request->input('bind_password',''),!empty($request->input('enabled')),!empty($request->input('auto_provision')),(string)$request->input('default_role','user'));
            $this->audit($request,'authentication.provider_saved','auth_provider',null,'Zapisano konfigurację LDAP','security','warning',['provider'=>$key]);
            Response::redirect(Url::to('/admin/authentication?message=ldap_saved'));
        }catch(\Throwable $e){Response::redirect(Url::to('/admin/authentication?message=ldap_error'));}
    }

    public function saveOidc(Request $request):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);
        try{
            $key=mb_strtolower(trim((string)$request->input('provider_key','oidc')));
            $config=[
                'issuer'=>trim((string)$request->input('issuer','')),
                'client_id'=>trim((string)$request->input('client_id','')),
                'scopes'=>trim((string)$request->input('scopes','openid profile email')),
                'claim_username'=>trim((string)$request->input('claim_username','preferred_username')),
                'claim_email'=>trim((string)$request->input('claim_email','email')),
                'claim_first_name'=>trim((string)$request->input('claim_first_name','given_name')),
                'claim_last_name'=>trim((string)$request->input('claim_last_name','family_name')),
                'claim_groups'=>trim((string)$request->input('claim_groups','groups')),
                'group_sync_mode'=>in_array((string)$request->input('group_sync_mode'),['off','create_missing','map_existing','sync'],true)?(string)$request->input('group_sync_mode'):'off',
            ];
            $this->oidc->saveProvider($key,trim((string)$request->input('display_name','OpenID Connect')),$config,(string)$request->input('client_secret',''),!empty($request->input('enabled')),!empty($request->input('auto_provision')),(string)$request->input('default_role','user'));
            $this->flags->set('oidc',true,$uid);
            $this->audit($request,'authentication.provider_saved','auth_provider',null,'Zapisano konfigurację OIDC','security','warning',['provider'=>$key]);
            Response::redirect(Url::to('/admin/authentication?message=oidc_saved'));
        }catch(\Throwable $e){Response::redirect(Url::to('/admin/authentication?message=oidc_error'));}
    }

    public function toggle(Request $request,array $params):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);$key=(string)($params['key']??'');
        try{$this->manager->setEnabled($key,!empty($request->input('enabled')));$this->audit($request,'authentication.provider_toggled','auth_provider',null,'Zmieniono stan providera','security','warning',['provider'=>$key,'enabled'=>!empty($request->input('enabled'))]);}catch(\Throwable){}
        Response::redirect(Url::to('/admin/authentication'));
    }

    public function toggleOidcFeature(Request $request):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);$this->flags->set('oidc',!empty($request->input('enabled')),$uid);Response::redirect(Url::to('/admin/authentication'));
    }

    private function requireAdmin():int
    {
        $uid=$this->requireAuth();if(!$this->authz->canAdmin($uid,'admin.security')&&!$this->authz->can($uid,'authentication.manage')){http_response_code(403);exit;}return$uid;
    }
}
