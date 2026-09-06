<?php
declare(strict_types=1);

namespace ImWiki\Support;

use ImWiki\Audit\AuditService;
use ImWiki\Auth\AuthenticationManager;
use ImWiki\Auth\ExternalIdentityService;
use ImWiki\Auth\OidcService;
use ImWiki\Controllers\ApiV2Controller;
use ImWiki\Controllers\AuthenticationAdminController;
use ImWiki\Controllers\AuthController;
use ImWiki\Controllers\EnterpriseAdminController;
use ImWiki\Controllers\HealthController;
use ImWiki\Controllers\OidcController;
use ImWiki\Controllers\PluginAdminController;
use ImWiki\Database\Connection;
use ImWiki\Database\Migrator;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Http\Router;
use ImWiki\Http\SafeHttpClient;
use ImWiki\Plugins\PluginManager;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Search\MySqlSearchEngine;
use ImWiki\Security\Authorization;
use ImWiki\Security\Crypto;
use ImWiki\Security\IpAccessPolicy;
use ImWiki\Security\RateLimiter;
use ImWiki\Security\SecurityHeaders;
use ImWiki\Security\SecurityPolicyService;
use ImWiki\Security\SsrfGuard;
use ImWiki\Services\ApiTokenService;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\AuthService;
use ImWiki\Services\ContentGovernanceService;
use ImWiki\Services\EnterpriseSpaceService;
use ImWiki\Services\HealthService;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PageService;
use ImWiki\Services\PermissionDiagnosticsService;
use ImWiki\Services\PropertySchemaService;
use ImWiki\Services\SearchService;
use ImWiki\Services\SessionService;
use ImWiki\Services\TotpService;
use ImWiki\Storage\StorageManager;
use ImWiki\View\View;
use PDO;
use Throwable;

final class Stage3FrontController
{
    private const PREFIXES=[
        '/admin/enterprise','/admin/plugins','/admin/authentication','/admin/health',
        '/auth/oidc/','/api/v2/'
    ];
    private const EXACT=['/login','/login/2fa','/logout','/health','/readiness'];

    public static function shouldHandle():bool
    {
        if(basename((string)($_SERVER['SCRIPT_FILENAME']??''))!=='index.php')return false;
        $path=self::path();
        if(in_array($path,self::EXACT,true))return true;
        foreach(self::PREFIXES as $prefix)if(str_starts_with($path,$prefix))return true;
        return false;
    }

    public static function handle(string $root):bool
    {
        $path=self::path();
        if($path==='/health'){
            Response::json(['status'=>'ok','version'=>defined('IMWIKI_VERSION')?IMWIKI_VERSION:'unknown','request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null]);
        }

        try{
            $db=(array)Config::get('db',[]);$pdo=Connection::create($db);$pdo->exec("SET time_zone = '+00:00'");$prefix=(string)($db['prefix']??'');
            $migrator=new Migrator($pdo,$root.'/database/migrations',$prefix);
            $pending=$migrator->pending();
            if($pending){
                if(in_array($path,['/login','/login/2fa','/logout'],true)||str_starts_with($path,'/auth/oidc/'))return false;
                if($path==='/readiness')Response::json(['status'=>'not_ready','checks'=>['database'=>true,'migrations'=>false,'storage'=>is_writable($root.'/storage')],'request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null],503);
                if(str_starts_with($path,'/api/'))Response::json(['error'=>['code'=>'schema_upgrade_required','message'=>'Database upgrade required.','request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null]],503);
                Response::redirect(Url::to('/upgrade.php'));
            }

            $view=new View($root.'/templates');$users=new UserRepository($pdo,$prefix);$spaces=new SpaceRepository($pdo,$prefix);$pages=new PageRepository($pdo,$prefix);$authz=new Authorization($pdo,$users,$prefix);$request=new Request();
            $securityPolicy=new SecurityPolicyService($pdo,$prefix);$ipPolicy=new IpAccessPolicy($pdo,$prefix);$clientIp=$ipPolicy->clientIp($_SERVER);if(filter_var($clientIp,FILTER_VALIDATE_IP))$_SERVER['REMOTE_ADDR']=$clientIp;
            if(self::setting($pdo,$prefix,'security.ip_restrictions_enabled','0')==='1'&&!$ipPolicy->allowed('global',$clientIp)){http_response_code(403);echo 'Access denied.';return true;}
            if(str_starts_with($path,'/admin/')&&self::setting($pdo,$prefix,'security.ip_restrictions_enabled','0')==='1'&&!$ipPolicy->allowed('admin',$clientIp)){http_response_code(403);echo 'Administrative access denied.';return true;}
            $forwardedProto=$ipPolicy->forwardedProto($_SERVER);$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||$forwardedProto==='https';$headerPolicy=$securityPolicy->headers();SecurityHeaders::send(['https'=>$https,'hsts'=>$headerPolicy['hsts'],'csp_report_only'=>$headerPolicy['csp_report_only']]);

            $crypto=new Crypto((string)Config::get('app.secret',''));$authService=new AuthService($users);$limiter=new RateLimiter($root.'/storage/cache/rate-limit');$totp=new TotpService($pdo,$prefix,$crypto);$sessions=new SessionService($pdo,$prefix);$flags=new FeatureFlags($pdo,$prefix);$jobs=new JobQueueService($pdo,$prefix);$notifications=new NotificationService($pdo,$prefix,$authz,$pages,$jobs);$storage=new StorageManager($pdo,$prefix,$root);
            $authenticationManager=new AuthenticationManager($pdo,$prefix,$authService,$crypto);$identities=new ExternalIdentityService($pdo,$prefix);$oidc=new OidcService($pdo,$prefix,$crypto,new SafeHttpClient(new SsrfGuard()));
            $enterpriseSpaces=new EnterpriseSpaceService($pdo,$prefix,$authz,$flags);$governance=new ContentGovernanceService($pdo,$prefix,$pages,$authz);$schemas=new PropertySchemaService($pdo,$prefix,$authz,$pages);$audit=new AuditService($pdo,$prefix,$root);$permissionDiagnostics=new PermissionDiagnosticsService($pdo,$prefix,$authz,$users,$pages);$health=new HealthService($pdo,$prefix,$root,$migrator,$jobs,$storage);$plugins=new PluginManager($pdo,$prefix,$root,$flags);
            $pageService=new PageService($pdo,$prefix);$attachments=new AttachmentService($pdo,$prefix,$pages,$authz,$root);$searchEngine=new MySqlSearchEngine($pdo,$prefix,$pages,$authz);$searchService=new SearchService($pdo,$prefix,$searchEngine);$apiTokens=new ApiTokenService($pdo,$prefix,$users);

            $auth=new AuthController($pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessions,$authenticationManager,$identities,$oidc,$flags);
            $oidcController=new OidcController($pdo,$prefix,$view,$users,$authz,$oidc,$identities,$authService,$sessions,$totp,$flags);
            $authenticationAdmin=new AuthenticationAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$authenticationManager,$oidc,$flags);
            $pluginAdmin=new PluginAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$plugins,$flags);
            $healthController=new HealthController($pdo,$prefix,$view,$users,$authz,$notifications,$health);
            $enterpriseAdmin=new EnterpriseAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$enterpriseSpaces,$governance,$schemas,$storage,$jobs,$securityPolicy,$ipPolicy,$flags,$audit,$health,$permissionDiagnostics,$migrator);
            $apiV2=new ApiV2Controller($pdo,$prefix,$apiTokens,$authz,$spaces,$pages,$pageService,$attachments,$searchService,$limiter,$audit,$flags);

            $router=new Router();
            $router->get('/login',[$auth,'loginForm']);$router->post('/login',[$auth,'login']);$router->get('/login/2fa',[$auth,'twoFactorForm']);$router->post('/login/2fa',[$auth,'twoFactorVerify']);$router->post('/logout',[$auth,'logout']);
            $router->get('/auth/oidc/{key}',[$oidcController,'start']);$router->get('/auth/oidc/{key}/callback',[$oidcController,'callback']);
            $router->get('/readiness',[$healthController,'readiness']);$router->get('/admin/health',[$healthController,'detailed']);
            $router->get('/admin/authentication',[$authenticationAdmin,'index']);$router->post('/admin/authentication/ldap',[$authenticationAdmin,'saveLdap']);$router->post('/admin/authentication/oidc',[$authenticationAdmin,'saveOidc']);$router->post('/admin/authentication/oidc-feature',[$authenticationAdmin,'toggleOidcFeature']);$router->post('/admin/authentication/providers/{key}/toggle',[$authenticationAdmin,'toggle']);
            $router->get('/admin/plugins',[$pluginAdmin,'index']);$router->post('/admin/plugins/install',[$pluginAdmin,'install']);$router->post('/admin/plugins/feature',[$pluginAdmin,'feature']);$router->post('/admin/plugins/{id}/toggle',[$pluginAdmin,'toggle']);$router->post('/admin/plugins/{id}/uninstall',[$pluginAdmin,'uninstall']);
            $router->get('/admin/enterprise',[$enterpriseAdmin,'index']);$router->post('/admin/enterprise/features',[$enterpriseAdmin,'feature']);$router->post('/admin/enterprise/categories',[$enterpriseAdmin,'createCategory']);$router->post('/admin/enterprise/spaces/lifecycle',[$enterpriseAdmin,'setSpaceLifecycle']);$router->post('/admin/enterprise/classifications',[$enterpriseAdmin,'saveClassification']);$router->post('/admin/enterprise/labels/rename',[$enterpriseAdmin,'renameLabel']);$router->post('/admin/enterprise/labels/delete',[$enterpriseAdmin,'deleteLabel']);$router->post('/admin/enterprise/ownership',[$enterpriseAdmin,'transferOwnership']);$router->post('/admin/enterprise/schemas',[$enterpriseAdmin,'createSchema']);$router->post('/admin/enterprise/security',[$enterpriseAdmin,'saveSecurity']);$router->post('/admin/enterprise/ip-rules',[$enterpriseAdmin,'addIpRule']);$router->post('/admin/enterprise/jobs/{id}/retry',[$enterpriseAdmin,'retryJob']);$router->post('/admin/enterprise/jobs/{id}/discard',[$enterpriseAdmin,'discardJob']);$router->post('/admin/enterprise/storage/verify',[$enterpriseAdmin,'verifyStorage']);$router->post('/admin/enterprise/storage/orphan',[$enterpriseAdmin,'cleanupOrphan']);$router->get('/admin/enterprise/audit/export',[$enterpriseAdmin,'auditExport']);
            $router->get('/api/v2/spaces',[$apiV2,'spaces']);$router->get('/api/v2/pages/{id}',[$apiV2,'page']);$router->post('/api/v2/pages',[$apiV2,'createPage']);$router->put('/api/v2/pages/{id}',[$apiV2,'updatePage']);$router->get('/api/v2/search',[$apiV2,'search']);$router->post('/api/v2/pages/{id}/attachments',[$apiV2,'uploadAttachment']);

            $router->dispatch($request);return true;
        }catch(Throwable $e){
            (new Logger($root.'/storage/logs',(bool)Config::get('app.debug',false)))->error('Stage3 front controller failure',['request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,'exception'=>get_class($e),'message'=>$e->getMessage(),'path'=>$path]);
            http_response_code(500);if(str_starts_with($path,'/api/')||$path==='/readiness'||$path==='/admin/health')Response::json(['error'=>['code'=>'internal_error','message'=>'Internal error','request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null]],500);echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>imWiki</title><main><h1>Wystąpił błąd.</h1><p>Reference: '.htmlspecialchars(defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'unknown',ENT_QUOTES,'UTF-8').'</p></main></html>';return true;
        }
    }

    private static function path():string
    {
        $uri=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';$base=Url::basePath();if($base!==''&&str_starts_with($uri,$base))$uri=substr($uri,strlen($base));return rtrim('/'.ltrim($uri,'/'),'/')?:'/';
    }
    private static function setting(PDO $pdo,string $prefix,string $key,string $default):string{$s=$pdo->prepare("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
}
