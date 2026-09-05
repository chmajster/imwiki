<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/storage/installed.lock') || !is_file(__DIR__ . '/config/config.php')) {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . ($base === '' ? '' : $base) . '/install.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';

use ImWiki\Controllers\AdminController;
use ImWiki\Controllers\AdminOperationsController;
use ImWiki\Controllers\ApiController;
use ImWiki\Controllers\ApiTokenController;
use ImWiki\Controllers\AttachmentController;
use ImWiki\Controllers\BackupController;
use ImWiki\Controllers\ImportExportController;
use ImWiki\Controllers\AuthController;
use ImWiki\Controllers\DiffController;
use ImWiki\Controllers\ContentAdminController;
use ImWiki\Controllers\InteractionController;
use ImWiki\Controllers\NotificationController;
use ImWiki\Controllers\MailController;
use ImWiki\Controllers\PasswordResetController;
use ImWiki\Controllers\PageAccessController;
use ImWiki\Controllers\PagePropertyController;
use ImWiki\Controllers\PageOperationController;
use ImWiki\Controllers\PublicShareController;
use ImWiki\Controllers\ProfileController;
use ImWiki\Controllers\PresenceController;
use ImWiki\Controllers\RestApiController;
use ImWiki\Controllers\SearchController;
use ImWiki\Controllers\SecurityController;
use ImWiki\Controllers\SecurityDashboardController;
use ImWiki\Controllers\SpaceAdminController;
use ImWiki\Controllers\SessionController;
use ImWiki\Controllers\SuggestionController;
use ImWiki\Controllers\TaskController;
use ImWiki\Controllers\TemplateController;
use ImWiki\Controllers\WikiController;
use ImWiki\Controllers\WorkflowController;
use ImWiki\Controllers\WebhookController;
use ImWiki\Controllers\UserAdminController;
use ImWiki\Database\Connection;
use ImWiki\Database\Migrator;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Http\Router;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\Crypto;
use ImWiki\Security\RateLimiter;
use ImWiki\Security\SsrfGuard;
use ImWiki\Services\ApiTokenService;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\BackupService;
use ImWiki\Services\ImportExportService;
use ImWiki\Services\MarkdownService;
use ImWiki\Services\AuthService;
use ImWiki\Services\DiffService;
use ImWiki\Services\DigestService;
use ImWiki\Services\RetentionService;
use ImWiki\Services\SchedulerService;
use ImWiki\Services\ContentHealthService;
use ImWiki\Services\ContentRenderer;
use ImWiki\Services\MentionService;
use ImWiki\Services\MailService;
use ImWiki\Services\SmtpClient;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\InteractionService;
use ImWiki\Services\PasswordResetService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PagePermissionService;
use ImWiki\Services\PagePropertyService;
use ImWiki\Services\PageOperationService;
use ImWiki\Services\PageService;
use ImWiki\Services\PresenceService;
use ImWiki\Services\PublicShareService;
use ImWiki\Services\SavedSearchService;
use ImWiki\Services\SessionService;
use ImWiki\Services\SpaceManagementService;
use ImWiki\Services\SearchService;
use ImWiki\Services\TaskService;
use ImWiki\Services\TemplateService;
use ImWiki\Services\TotpService;
use ImWiki\Services\WorkflowService;
use ImWiki\Services\WebhookService;
use ImWiki\Services\UserManagementService;
use ImWiki\Support\Cache;
use ImWiki\Support\Config;
use ImWiki\Support\EventDispatcher;
use ImWiki\Support\Logger;
use ImWiki\Support\Url;
use ImWiki\View\View;

try {
    date_default_timezone_set((string)Config::get('app.timezone','Europe/Warsaw'));
    $db=(array)Config::get('db',[]);$pdo=Connection::create($db);$pdo->exec("SET time_zone = '+00:00'");$prefix=(string)($db['prefix']??'');
    $view=new View(__DIR__.'/templates');$users=new UserRepository($pdo,$prefix);$spaces=new SpaceRepository($pdo,$prefix);$pages=new PageRepository($pdo,$prefix);$authz=new Authorization($pdo,$users,$prefix);$authService=new AuthService($users);$limiter=new RateLimiter(__DIR__.'/storage/cache/rate-limit');$crypto=new Crypto((string)Config::get('app.secret',''));$totp=new TotpService($pdo,$prefix,$crypto);$sessionService=new SessionService($pdo,$prefix);
    $request=new Request();

    $migrator=new Migrator($pdo,__DIR__.'/database/migrations',$prefix);$pending=$migrator->pending();
    if($pending){
        $auth=new AuthController($pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessionService);$path=$request->path();
        if(in_array($path,['/login','/logout','/login/2fa'],true)){
            $router=new Router();$router->get('/login',[$auth,'loginForm']);$router->post('/login',[$auth,'login']);$router->get('/login/2fa',[$auth,'twoFactorForm']);$router->post('/login/2fa',[$auth,'twoFactorVerify']);$router->post('/logout',[$auth,'logout']);$router->dispatch($request);exit;
        }
        $uid=(int)($_SESSION['user_id']??0);if($uid<=0||!$users->find($uid))Response::redirect(Url::to('/login'));
        if($authz->isAdmin($uid))Response::redirect(Url::to('/upgrade.php'));
        http_response_code(503);echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Aktualizacja wymagana</title><link rel="stylesheet" href="'.htmlspecialchars(Url::to('/public/assets/app.css'),ENT_QUOTES,'UTF-8').'"><main class="installer"><div class="card"><h1>Wymagana aktualizacja bazy danych</h1><p>Administrator musi zakończyć migracje przed dalszym użyciem aplikacji.</p><p class="muted">Reference: '.htmlspecialchars(IMWIKI_REQUEST_ID,ENT_QUOTES,'UTF-8').'</p></div></main></html>';exit;
    }

    if(isset($_SESSION['user_id'])&&!$sessionService->ensureCurrent((int)$_SESSION['user_id'],$request->ip(),$request->userAgent())){$authService->logout();Response::redirect(Url::to('/login'));}
    if(isset($_SESSION['user_id'])){$sessionUser=$users->find((int)$_SESSION['user_id']);if($sessionUser&&!empty($sessionUser['force_password_change'])&&!in_array($request->path(),['/profile','/profile/password','/logout'],true))Response::redirect(Url::to('/profile?force=1'));}
    $maintenance=(string)($pdo->query("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key='maintenance.enabled' LIMIT 1")->fetchColumn()?:'0')==='1';if($maintenance&&!in_array($request->path(),['/login','/login/2fa','/logout','/admin/system','/admin/maintenance'],true)){$muid=(int)($_SESSION['user_id']??0);if($muid<=0||!$authz->isAdmin($muid)){http_response_code(503);echo $view->render('errors/maintenance.php',['currentUser'=>$muid>0?$users->find($muid):null,'authz'=>$authz,'url'=>Url::class,'notificationCount'=>0,'requestId'=>IMWIKI_REQUEST_ID]);exit;}}

    $jobs=new JobQueueService($pdo,$prefix);$notifications=new NotificationService($pdo,$prefix,$authz,$pages,$jobs);$mailService=new MailService($pdo,$prefix,$crypto,new SmtpClient());$digestService=new DigestService($pdo,$prefix,$notifications,$jobs);$retentionService=new RetentionService($pdo,$prefix,__DIR__);$schedulerService=new SchedulerService($retentionService,$digestService,__DIR__.'/storage/cache');$cache=new Cache(__DIR__.'/storage/cache');$passwordResets=new PasswordResetService($pdo,$prefix,$users,$sessionService);$webhookService=new WebhookService($pdo,$prefix,$authz,$crypto,new SsrfGuard(),$jobs);
    register_shutdown_function(static function()use($jobs,$mailService,$crypto,$webhookService,$notifications,$schedulerService):void{if(function_exists('fastcgi_finish_request'))@fastcgi_finish_request();try{$schedulerService->opportunistic();$jobs->process(3,['email'=>static fn(array $p)=>$mailService->send((string)$p['to'],(string)$p['subject'],(string)$p['html'],(string)$p['text']),'encrypted_email'=>static function(array $p)use($mailService,$crypto):void{$data=json_decode($crypto->decrypt((string)($p['envelope']??'')),true,512,JSON_THROW_ON_ERROR);$mailService->send((string)$data['to'],(string)$data['subject'],(string)$data['html'],(string)$data['text']);},'notification_email'=>static function(array $p)use($notifications,$mailService):void{$mail=$notifications->emailForNotification((int)($p['user_id']??0),(int)($p['notification_id']??0));if($mail)$mailService->send($mail['to'],$mail['subject'],$mail['html'],$mail['text']);},'notification_digest'=>static function(array $p)use($notifications,$mailService):void{$mail=$notifications->digestEmail((int)($p['user_id']??0),(string)($p['from']??''),(string)($p['to']??''));if($mail)$mailService->send($mail['to'],$mail['subject'],$mail['html'],$mail['text']);},'webhook'=>static fn(array $p)=>$webhookService->deliver($p)]);}catch(Throwable){}});
    $events=new EventDispatcher();$mentions=new MentionService($pdo,$prefix,$notifications);
    $events->on('page.created',static fn(array $e)=>$notifications->notifyWatchers('page.created',(int)$e['actor_id'],(int)$e['page_id'],(int)$e['space_id'],(string)$e['url'],['title'=>$e['title']??'']));
    $events->on('page.updated',static fn(array $e)=>$notifications->notifyWatchers('page.updated',(int)$e['actor_id'],(int)$e['page_id'],(int)$e['space_id'],(string)$e['url'],['title'=>$e['title']??'','version'=>$e['version']??null]));
    $events->on('comment.created',static fn(array $e)=>$notifications->notifyPageCommentWatchers((int)$e['actor_id'],(int)$e['page_id'],(string)$e['url'],['comment_id'=>$e['comment_id']??null]));
    foreach(WebhookService::EVENTS as $webhookEvent)$events->on($webhookEvent,static fn(array $e)=>$webhookService->enqueueEvent($webhookEvent,$e));

    $pageService=new PageService($pdo,$prefix,$mentions,$events);$presenceService=new PresenceService($pdo,$prefix,$pages,$authz);$taskService=new TaskService($pdo,$prefix,$pages,$authz,$notifications);$attachmentService=new AttachmentService($pdo,$prefix,$pages,$authz,__DIR__);$permissionService=new PagePermissionService($pdo,$prefix,$pages,$authz,$notifications);$diffService=new DiffService();$apiTokenService=new ApiTokenService($pdo,$prefix,$users);$workflowService=new WorkflowService($pdo,$prefix,$pages,$authz,$notifications);$propertyService=new PagePropertyService($pdo,$prefix,$pages,$authz);$searchService=new SearchService($pdo,$prefix);$savedSearchService=new SavedSearchService($pdo,$prefix);$publicShareService=new PublicShareService($pdo,$prefix);$interactionService=new InteractionService($pdo,$prefix,$pages,$authz);$contentHealthService=new ContentHealthService($pdo,$prefix);$contentRenderer=new ContentRenderer($pdo,$prefix,$pages,$authz);$pageOperationService=new PageOperationService($pdo,$prefix,$pages,$authz,$pageService,$events);$markdownService=new MarkdownService();$importExportService=new ImportExportService($pdo,$prefix,$pages,$spaces,$authz,$pageService,$markdownService,__DIR__);$backupService=new BackupService($pdo,$prefix,__DIR__);$userManagementService=new UserManagementService($pdo,$prefix,$sessionService);$templateService=new TemplateService($pdo,$prefix,$authz);$spaceManagementService=new SpaceManagementService($pdo,$prefix,$spaces,$authz);

    $auth=new AuthController($pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessionService);
    $wiki=new WikiController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$pages,$pageService,$taskService,$attachmentService,$mentions,$events,$workflowService,$propertyService,$interactionService,$contentHealthService,$templateService,$contentRenderer);
    $admin=new AdminController($pdo,$prefix,$view,$users,$authz,$notifications);$adminOperations=new AdminOperationsController($pdo,$prefix,$view,$users,$authz,$notifications,$retentionService,$schedulerService,$cache,__DIR__);
    $api=new ApiController($pdo,$prefix,$view,$users,$authz,$pages,$limiter);
    $notificationController=new NotificationController($pdo,$prefix,$view,$users,$authz,$notifications);
    $taskController=new TaskController($pdo,$prefix,$view,$users,$authz,$notifications,$taskService,$pages,$spaces);
    $accessController=new PageAccessController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$permissionService);
    $attachmentController=new AttachmentController($pdo,$prefix,$view,$users,$authz,$notifications,$attachmentService);
    $diffController=new DiffController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$diffService);
    $apiTokenController=new ApiTokenController($pdo,$prefix,$view,$users,$authz,$notifications,$apiTokenService);
    $restApi=new RestApiController($apiTokenService,$authz,$spaces,$pages,$pageService,$attachmentService);
    $workflowController=new WorkflowController($pdo,$prefix,$view,$users,$authz,$notifications,$workflowService);
    $propertyController=new PagePropertyController($pdo,$prefix,$view,$users,$authz,$notifications,$propertyService);
    $searchController=new SearchController($pdo,$prefix,$view,$users,$authz,$notifications,$searchService,$savedSearchService,$pages);
    $suggestionController=new SuggestionController($pdo,$prefix,$view,$users,$authz,$notifications,$limiter,$searchService,$savedSearchService,$pages,$spaces);
    $publicShareController=new PublicShareController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$publicShareService);
    $securityController=new SecurityController($pdo,$prefix,$view,$users,$authz,$notifications,$totp);
    $sessionController=new SessionController($pdo,$prefix,$view,$users,$authz,$notifications,$sessionService);
    $mailController=new MailController($pdo,$prefix,$view,$users,$authz,$notifications,$mailService,$jobs);
    $passwordResetController=new PasswordResetController($pdo,$prefix,$view,$users,$authz,$passwordResets,$mailService,$jobs,$crypto,$limiter);
    $interactionController=new InteractionController($pdo,$prefix,$view,$users,$authz,$notifications,$interactionService);
    $webhookController=new WebhookController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$webhookService);
    $userAdminController=new UserAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$userManagementService);
    $profileController=new ProfileController($pdo,$prefix,$view,$users,$authz,$notifications,$sessionService);$presenceController=new PresenceController($pdo,$prefix,$view,$users,$authz,$notifications,$presenceService);
    $contentAdminController=new ContentAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$contentHealthService);
    $securityDashboardController=new SecurityDashboardController($pdo,$prefix,$view,$users,$authz,$notifications);
    $pageOperationController=new PageOperationController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$spaces,$pageOperationService);
    $templateController=new TemplateController($pdo,$prefix,$view,$users,$authz,$notifications,$templateService,$spaces);$spaceAdminController=new SpaceAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$pages,$spaceManagementService);$importExportController=new ImportExportController($pdo,$prefix,$view,$users,$authz,$notifications,$importExportService);$backupController=new BackupController($pdo,$prefix,$view,$users,$authz,$notifications,$backupService);

    $router=new Router();
    $router->get('/',fn()=>Response::redirect(Url::to(isset($_SESSION['user_id'])?'/dashboard':'/login')));
    $router->get('/login',[$auth,'loginForm']);$router->post('/login',[$auth,'login']);$router->get('/forgot-password',[$passwordResetController,'requestForm']);$router->post('/forgot-password',[$passwordResetController,'requestReset']);$router->get('/password-reset',[$passwordResetController,'resetForm']);$router->post('/password-reset',[$passwordResetController,'reset']);$router->get('/login/2fa',[$auth,'twoFactorForm']);$router->post('/login/2fa',[$auth,'twoFactorVerify']);$router->post('/logout',[$auth,'logout']);
    $router->get('/dashboard',[$wiki,'dashboard']);$router->get('/profile/dashboard',[$wiki,'dashboardSettings']);$router->post('/profile/dashboard',[$wiki,'saveDashboardSettings']);$router->get('/recent',[$wiki,'recentViewed']);$router->post('/recent/clear',[$wiki,'clearRecent']);$router->get('/drafts',[$wiki,'drafts']);$router->post('/drafts/{id}/delete',[$wiki,'deleteDraft']);
    $router->get('/spaces',[$wiki,'spaces']);$router->post('/spaces',[$wiki,'createSpace']);$router->get('/spaces/{key}',[$wiki,'space']);$router->get('/spaces/{key}/export.zip',[$importExportController,'spaceZip']);$router->post('/spaces/{key}/import',[$importExportController,'import']);$router->get('/spaces/{key}/settings',[$spaceAdminController,'settings']);$router->post('/spaces/{key}/settings',[$spaceAdminController,'save']);$router->post('/spaces/{key}/archive',[$spaceAdminController,'archive']);$router->get('/spaces/{key}/webhooks',[$webhookController,'index']);$router->post('/spaces/{key}/webhooks',[$webhookController,'create']);$router->post('/spaces/{key}/webhooks/{id}/revoke',[$webhookController,'revoke']);$router->post('/spaces/{key}/watch',[$wiki,'watchSpace']);$router->post('/spaces/{key}/favorite',[$wiki,'favoriteSpace']);
    $router->get('/spaces/{key}/pages/create',[$wiki,'newPage']);$router->post('/spaces/{key}/pages/create',[$wiki,'createPage']);$router->get('/spaces/{key}/pages/{slug}',[$wiki,'friendlyPage']);
    $router->get('/pages/{id}',[$wiki,'page']);$router->get('/pages/{id}/edit',[$wiki,'editPage']);$router->post('/pages/{id}/edit',[$wiki,'updatePage']);
    $router->get('/pages/{id}/export.md',[$importExportController,'pageMarkdown']);$router->get('/pages/{id}/export.html',[$importExportController,'pageHtml']);$router->get('/pages/{id}/history',[$wiki,'history']);$router->get('/pages/{id}/move',[$pageOperationController,'dialog']);$router->post('/pages/{id}/move',[$pageOperationController,'move']);$router->post('/pages/{id}/copy',[$pageOperationController,'copy']);$router->post('/pages/{id}/archive',[$pageOperationController,'archive']);$router->post('/pages/{id}/trash',[$pageOperationController,'trash']);$router->get('/pages/{id}/diff',[$diffController,'compare']);$router->post('/pages/{id}/restore',[$wiki,'restore']);
    $router->post('/pages/{id}/comments',[$wiki,'comment']);$router->post('/pages/{id}/favorite',[$wiki,'favorite']);$router->post('/pages/{id}/watch',[$wiki,'watchPage']);
    $router->post('/pages/{id}/reactions',[$interactionController,'pageReaction']);$router->post('/pages/{id}/comments/{commentId}/reactions',[$interactionController,'commentReaction']);$router->post('/pages/{id}/comments/{commentId}/status',[$interactionController,'threadStatus']);$router->post('/pages/{id}/inline-comments',[$interactionController,'inlineCreate']);$router->post('/pages/{id}/inline-comments/{inlineId}/status',[$interactionController,'inlineStatus']);
    $router->post('/pages/{id}/tasks',[$taskController,'create']);$router->post('/tasks/{id}/complete',[$taskController,'complete']);$router->get('/tasks',[$taskController,'mine']);
    $router->get('/pages/{id}/restrictions',[$accessController,'show']);$router->get('/pages/{id}/public-shares',[$publicShareController,'manage']);$router->post('/pages/{id}/public-shares',[$publicShareController,'create']);$router->post('/pages/{id}/public-shares/{shareId}/revoke',[$publicShareController,'revoke']);$router->post('/pages/{id}/restrictions/mode',[$accessController,'setMode']);$router->post('/pages/{id}/restrictions/grants',[$accessController,'grant']);$router->post('/pages/{id}/restrictions/grants/{grantId}/revoke',[$accessController,'revoke']);
    $router->post('/pages/{id}/attachments',[$attachmentController,'upload']);$router->get('/attachments/{id}/download',[$attachmentController,'download']);$router->get('/attachments/{id}/preview',[$attachmentController,'preview']);$router->get('/attachments/{id}/versions',[$attachmentController,'versions']);$router->get('/attachment-versions/{versionId}/download',[$attachmentController,'downloadVersion']);
    $router->post('/api/pages/{id}/draft',[$api,'autosave']);$router->post('/api/pages/{id}/presence',[$presenceController,'heartbeat']);$router->post('/api/pages/{id}/tree-move',[$pageOperationController,'treeMove']);$router->get('/api/users/autocomplete',[$api,'users']);$router->get('/api/search/suggestions',[$suggestionController,'search']);
    $router->get('/notifications',[$notificationController,'index']);$router->get('/profile/notifications',[$notificationController,'preferences']);$router->post('/profile/notifications',[$notificationController,'savePreferences']);$router->post('/notifications/{id}/read',[$notificationController,'markRead']);$router->post('/notifications/read-all',[$notificationController,'markAllRead']);
    $router->get('/profile',[$profileController,'index']);$router->post('/profile',[$profileController,'update']);$router->post('/profile/password',[$profileController,'password']);$router->get('/profile/security',[$securityController,'index']);$router->get('/profile/sessions',[$sessionController,'index']);$router->post('/profile/sessions/revoke-others',[$sessionController,'revokeOthers']);$router->post('/profile/sessions/{id}/revoke',[$sessionController,'revoke']);$router->post('/profile/security/2fa/begin',[$securityController,'begin2fa']);$router->post('/profile/security/2fa/confirm',[$securityController,'confirm2fa']);$router->post('/profile/security/2fa/disable',[$securityController,'disable2fa']);
    $router->get('/api-tokens',[$apiTokenController,'index']);$router->post('/api-tokens',[$apiTokenController,'create']);$router->post('/api-tokens/{id}/revoke',[$apiTokenController,'revoke']);
    $router->post('/pages/{id}/properties',[$propertyController,'set']);$router->post('/pages/{id}/properties/{propertyId}/remove',[$propertyController,'remove']);
    $router->post('/pages/{id}/workflow/request',[$workflowController,'request']);$router->post('/pages/{id}/workflow/decision',[$workflowController,'decide']);$router->post('/pages/{id}/workflow/publish',[$workflowController,'publish']);$router->post('/pages/{id}/workflow/draft',[$workflowController,'draft']);$router->post('/admin/workflow',[$workflowController,'setting']);
    $router->get('/api/v1/spaces',[$restApi,'spaces']);$router->get('/api/v1/pages/{id}',[$restApi,'page']);$router->post('/api/v1/pages',[$restApi,'createPage']);$router->put('/api/v1/pages/{id}',[$restApi,'updatePage']);$router->get('/api/v1/search',[$restApi,'search']);$router->post('/api/v1/pages/{id}/attachments',[$restApi,'uploadAttachment']);$router->get('/api/v1/attachments/{id}',[$restApi,'downloadAttachment']);
    $router->get('/share/{token}',[$publicShareController,'view']);$router->post('/share/{token}',[$publicShareController,'view']);
    $router->get('/search',[$searchController,'index']);$router->post('/search/save',[$searchController,'save']);$router->post('/saved-searches/{id}/remove',[$searchController,'remove']);
    $router->get('/admin/logs',[$adminOperations,'logs']);$router->get('/admin/retention',[$adminOperations,'retention']);$router->post('/admin/retention',[$adminOperations,'saveRetention']);$router->post('/admin/retention/cleanup',[$adminOperations,'cleanup']);$router->get('/admin/database',[$adminOperations,'database']);$router->post('/admin/cache/clear',[$adminOperations,'clearCache']);$router->get('/admin/users',[$userAdminController,'users']);$router->get('/admin/users/create',[$userAdminController,'createForm']);$router->post('/admin/users/create',[$userAdminController,'create']);$router->get('/admin/users/{id}/edit',[$userAdminController,'editForm']);$router->post('/admin/users/{id}/edit',[$userAdminController,'update']);$router->post('/admin/users/{id}/password',[$userAdminController,'resetPassword']);$router->post('/admin/users/{id}/delete',[$userAdminController,'delete']);$router->get('/admin/groups',[$userAdminController,'groups']);$router->post('/admin/groups',[$userAdminController,'createGroup']);$router->get('/admin/groups/{id}',[$userAdminController,'group']);$router->post('/admin/groups/{id}',[$userAdminController,'updateGroup']);$router->post('/admin/groups/{id}/delete',[$userAdminController,'deleteGroup']);$router->post('/admin/groups/{id}/members',[$userAdminController,'addMember']);$router->post('/admin/groups/{id}/members/{userId}/remove',[$userAdminController,'removeMember']);$router->get('/admin/system',[$admin,'system']);$router->get('/admin/backup',[$backupController,'index']);$router->post('/admin/backup/create',[$backupController,'create']);$router->get('/admin/content',[$contentAdminController,'index']);$router->get('/admin/templates',[$templateController,'index']);$router->post('/admin/templates',[$templateController,'create']);$router->get('/admin/templates/{id}',[$templateController,'edit']);$router->post('/admin/templates/{id}',[$templateController,'update']);$router->post('/admin/templates/{id}/clone',[$templateController,'clone']);$router->post('/admin/templates/{id}/archive',[$templateController,'archive']);$router->get('/admin/security',[$securityDashboardController,'index']);$router->get('/admin/trash',[$pageOperationController,'trashList']);$router->post('/admin/trash/{id}/restore',[$pageOperationController,'restore']);$router->post('/admin/trash/{id}/purge',[$pageOperationController,'purge']);$router->post('/admin/maintenance',[$admin,'maintenance']);$router->get('/admin/mail',[$mailController,'index']);$router->post('/admin/mail',[$mailController,'save']);$router->post('/admin/mail/test',[$mailController,'test']);$router->post('/admin/public-sharing',[$publicShareController,'adminSetting']);

    $result=$router->dispatch($request);
    if($result===null&&http_response_code()===404)echo $view->render('errors/404.php',['currentUser'=>isset($_SESSION['user_id'])?$users->find((int)$_SESSION['user_id']):null,'authz'=>$authz,'url'=>Url::class,'notificationCount'=>$notifications->unreadCount((int)($_SESSION['user_id']??0)),'requestId'=>IMWIKI_REQUEST_ID]);
} catch (Throwable $e) {
    (new Logger(__DIR__.'/storage/logs',(bool)Config::get('app.debug',false)))->error('Unhandled exception',['request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,'exception'=>get_class($e),'message'=>$e->getMessage()]);
    http_response_code(500);$ref=defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'unknown';
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Błąd imWiki</title><link rel="stylesheet" href="'.htmlspecialchars(Url::to('/public/assets/app.css'),ENT_QUOTES,'UTF-8').'"><main class="installer"><div class="card"><h1>Wystąpił błąd aplikacji.</h1><p>Szczegóły techniczne zapisano w logu serwera.</p><p class="muted">Reference: '.htmlspecialchars($ref,ENT_QUOTES,'UTF-8').'</p></div></main></html>';
}
