<?php
declare(strict_types=1);

namespace ImWiki\Bootstrap;

use ImWiki\Controllers\AdminController;
use ImWiki\Controllers\AdminOperationsController;
use ImWiki\Controllers\ApiController;
use ImWiki\Controllers\ApiTokenController;
use ImWiki\Controllers\AttachmentController;
use ImWiki\Controllers\AuthController;
use ImWiki\Controllers\BackupController;
use ImWiki\Controllers\ContentAdminController;
use ImWiki\Controllers\DiffController;
use ImWiki\Controllers\ImportExportController;
use ImWiki\Controllers\InteractionController;
use ImWiki\Controllers\MailController;
use ImWiki\Controllers\NotificationController;
use ImWiki\Controllers\PageAccessController;
use ImWiki\Controllers\PageOperationController;
use ImWiki\Controllers\PagePropertyController;
use ImWiki\Controllers\PasswordResetController;
use ImWiki\Controllers\PresenceController;
use ImWiki\Controllers\ProfileController;
use ImWiki\Controllers\PublicShareController;
use ImWiki\Controllers\RestApiController;
use ImWiki\Controllers\SearchController;
use ImWiki\Controllers\SecurityController;
use ImWiki\Controllers\SecurityDashboardController;
use ImWiki\Controllers\SessionController;
use ImWiki\Controllers\SpaceAdminController;
use ImWiki\Controllers\SuggestionController;
use ImWiki\Controllers\TaskController;
use ImWiki\Controllers\TemplateController;
use ImWiki\Controllers\TreeController;
use ImWiki\Controllers\UserAdminController;
use ImWiki\Controllers\WebhookController;
use ImWiki\Controllers\WikiController;
use ImWiki\Controllers\WorkflowController;
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
use ImWiki\Services\AuthService;
use ImWiki\Services\BackupService;
use ImWiki\Services\ContentHealthService;
use ImWiki\Services\ContentRenderer;
use ImWiki\Services\DiffService;
use ImWiki\Services\DigestService;
use ImWiki\Services\ImportExportService;
use ImWiki\Services\InteractionService;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\JobRunner;
use ImWiki\Services\MailService;
use ImWiki\Services\MarkdownService;
use ImWiki\Services\MentionService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PageOperationService;
use ImWiki\Services\PagePermissionService;
use ImWiki\Services\PagePropertyService;
use ImWiki\Services\PageService;
use ImWiki\Services\PasswordResetService;
use ImWiki\Services\PresenceService;
use ImWiki\Services\PublicShareService;
use ImWiki\Services\RetentionService;
use ImWiki\Services\SavedSearchService;
use ImWiki\Services\SchedulerService;
use ImWiki\Services\SearchService;
use ImWiki\Services\SessionService;
use ImWiki\Services\SlugService;
use ImWiki\Services\SmtpClient;
use ImWiki\Services\SpaceManagementService;
use ImWiki\Services\TaskService;
use ImWiki\Services\TemplateService;
use ImWiki\Services\TotpService;
use ImWiki\Services\UserManagementService;
use ImWiki\Services\WebhookService;
use ImWiki\Services\WorkflowService;
use ImWiki\Support\Cache;
use ImWiki\Support\Config;
use ImWiki\Support\EventDispatcher;
use ImWiki\Support\Logger;
use ImWiki\Support\Url;
use ImWiki\View\View;
use Throwable;

final class Application
{
    public function __construct(private readonly string $root) {}

    public function run(): void
    {
        try {
            $this->runApplication();
        } catch (Throwable $e) {
            $this->renderUnhandledError($e);
        }
    }

    private function runApplication(): void
    {
        date_default_timezone_set((string)Config::get('app.timezone','Europe/Warsaw'));
        $db=(array)Config::get('db',[]);
        $pdo=Connection::create($db);
        $pdo->exec("SET time_zone = '+00:00'");
        $prefix=(string)($db['prefix']??'');

        $view=new View($this->root.'/templates');
        $users=new UserRepository($pdo,$prefix);
        $spaces=new SpaceRepository($pdo,$prefix);
        $pages=new PageRepository($pdo,$prefix);
        $authz=new Authorization($pdo,$users,$prefix);
        $authService=new AuthService($users);
        $limiter=new RateLimiter($this->root.'/storage/cache/rate-limit');
        $crypto=new Crypto((string)Config::get('app.secret',''));
        $totp=new TotpService($pdo,$prefix,$crypto);
        $sessionService=new SessionService($pdo,$prefix);
        $request=new Request();

        $migrator=new Migrator($pdo,$this->root.'/database/migrations',$prefix);
        if($migrator->pending()){
            $this->handlePendingMigrations($request,$pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessionService);
            return;
        }

        if(isset($_SESSION['user_id'])&&!$sessionService->ensureCurrent((int)$_SESSION['user_id'],$request->ip(),$request->userAgent())){
            $authService->logout();
            Response::redirect(Url::to('/login'));
        }
        if(isset($_SESSION['user_id'])){
            $sessionUser=$users->find((int)$_SESSION['user_id']);
            if($sessionUser&&!empty($sessionUser['force_password_change'])&&!in_array($request->path(),['/profile','/profile/password','/logout'],true))Response::redirect(Url::to('/profile?force=1'));
        }

        $maintenance=(string)($pdo->query("SELECT setting_value FROM `{$prefix}settings` WHERE setting_key='maintenance.enabled' LIMIT 1")->fetchColumn()?:'0')==='1';
        if($maintenance&&!in_array($request->path(),['/login','/login/2fa','/logout','/admin/system','/admin/maintenance'],true)){
            $uid=(int)($_SESSION['user_id']??0);
            if($uid<=0||!$authz->isAdmin($uid)){
                http_response_code(503);
                echo $view->render('errors/maintenance.php',['currentUser'=>$uid>0?$users->find($uid):null,'authz'=>$authz,'url'=>Url::class,'notificationCount'=>0,'requestId'=>IMWIKI_REQUEST_ID]);
                return;
            }
        }

        $jobs=new JobQueueService($pdo,$prefix);
        $notifications=new NotificationService($pdo,$prefix,$authz,$pages,$jobs);
        $mailService=new MailService($pdo,$prefix,$crypto,new SmtpClient());
        $digestService=new DigestService($pdo,$prefix,$notifications,$jobs);
        $retentionService=new RetentionService($pdo,$prefix,$this->root);
        $schedulerService=new SchedulerService($retentionService,$digestService,$this->root.'/storage/cache');
        $cache=new Cache($this->root.'/storage/cache');
        $passwordResets=new PasswordResetService($pdo,$prefix,$users,$sessionService);
        $webhookService=new WebhookService($pdo,$prefix,$authz,$crypto,new SsrfGuard(),$jobs);
        $jobRunner=new JobRunner($jobs,$mailService,$crypto,$webhookService,$notifications);
        $this->registerDeferredWork($schedulerService,$jobRunner);

        $workflowService=new WorkflowService($pdo,$prefix,$pages,$authz,$notifications);
        $events=new EventDispatcher();
        $mentions=new MentionService($pdo,$prefix,$notifications);
        $events->on('page.created',static fn(array $event)=>$notifications->notifyWatchers('page.created',(int)$event['actor_id'],(int)$event['page_id'],(int)$event['space_id'],(string)$event['url'],['title'=>$event['title']??'']));
        $events->on('page.updated',static fn(array $event)=>$notifications->notifyWatchers('page.updated',(int)$event['actor_id'],(int)$event['page_id'],(int)$event['space_id'],(string)$event['url'],['title'=>$event['title']??'','version'=>$event['version']??null]));
        $events->on('comment.created',static fn(array $event)=>$notifications->notifyPageCommentWatchers((int)$event['actor_id'],(int)$event['page_id'],(string)$event['url'],['comment_id'=>$event['comment_id']??null]));
        foreach(WebhookService::EVENTS as $eventName){
            $events->on($eventName,static fn(array $event)=>$webhookService->enqueueEvent($eventName,$event));
        }

        $slugs=new SlugService($pdo,$prefix);
        $pageService=new PageService($pdo,$prefix,$mentions,$events,$workflowService,$slugs);
        $presenceService=new PresenceService($pdo,$prefix,$pages,$authz);
        $taskService=new TaskService($pdo,$prefix,$pages,$authz,$notifications);
        $attachmentService=new AttachmentService($pdo,$prefix,$pages,$authz,$this->root);
        $permissionService=new PagePermissionService($pdo,$prefix,$pages,$authz,$notifications);
        $diffService=new DiffService();
        $apiTokenService=new ApiTokenService($pdo,$prefix,$users);
        $propertyService=new PagePropertyService($pdo,$prefix,$pages,$authz);
        $searchService=new SearchService($pdo,$prefix);
        $savedSearchService=new SavedSearchService($pdo,$prefix);
        $publicShareService=new PublicShareService($pdo,$prefix);
        $interactionService=new InteractionService($pdo,$prefix,$pages,$authz);
        $contentHealthService=new ContentHealthService($pdo,$prefix);
        $contentRenderer=new ContentRenderer($pdo,$prefix,$pages,$authz);
        $pageOperationService=new PageOperationService($pdo,$prefix,$pages,$authz,$pageService,$events,$slugs);
        $markdownService=new MarkdownService();
        $importExportService=new ImportExportService($pdo,$prefix,$pages,$spaces,$authz,$pageService,$markdownService,$this->root);
        $backupService=new BackupService($pdo,$prefix,$this->root);
        $userManagementService=new UserManagementService($pdo,$prefix,$sessionService);
        $templateService=new TemplateService($pdo,$prefix,$authz);
        $spaceManagementService=new SpaceManagementService($pdo,$prefix,$spaces,$authz);

        $controllers=[];
        $controllers['auth']=new AuthController($pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessionService);
        $controllers['wiki']=new WikiController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$pages,$pageService,$taskService,$attachmentService,$mentions,$events,$workflowService,$propertyService,$interactionService,$contentHealthService,$templateService,$contentRenderer);
        $controllers['admin']=new AdminController($pdo,$prefix,$view,$users,$authz,$notifications);
        $controllers['adminOperations']=new AdminOperationsController($pdo,$prefix,$view,$users,$authz,$notifications,$retentionService,$schedulerService,$cache,$this->root);
        $controllers['api']=new ApiController($pdo,$prefix,$view,$users,$authz,$pages,$limiter);
        $controllers['notifications']=new NotificationController($pdo,$prefix,$view,$users,$authz,$notifications);
        $controllers['tasks']=new TaskController($pdo,$prefix,$view,$users,$authz,$notifications,$taskService,$pages,$spaces);
        $controllers['access']=new PageAccessController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$permissionService);
        $controllers['attachments']=new AttachmentController($pdo,$prefix,$view,$users,$authz,$notifications,$attachmentService);
        $controllers['diff']=new DiffController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$diffService);
        $controllers['apiTokens']=new ApiTokenController($pdo,$prefix,$view,$users,$authz,$notifications,$apiTokenService);
        $controllers['restApi']=new RestApiController($apiTokenService,$authz,$spaces,$pages,$pageService,$attachmentService);
        $controllers['workflow']=new WorkflowController($pdo,$prefix,$view,$users,$authz,$notifications,$workflowService);
        $controllers['properties']=new PagePropertyController($pdo,$prefix,$view,$users,$authz,$notifications,$propertyService);
        $controllers['search']=new SearchController($pdo,$prefix,$view,$users,$authz,$notifications,$searchService,$savedSearchService,$pages);
        $controllers['suggestions']=new SuggestionController($pdo,$prefix,$view,$users,$authz,$notifications,$limiter,$searchService,$savedSearchService,$pages,$spaces);
        $controllers['publicShares']=new PublicShareController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$publicShareService);
        $controllers['security']=new SecurityController($pdo,$prefix,$view,$users,$authz,$notifications,$totp);
        $controllers['sessions']=new SessionController($pdo,$prefix,$view,$users,$authz,$notifications,$sessionService);
        $controllers['mail']=new MailController($pdo,$prefix,$view,$users,$authz,$notifications,$mailService,$jobs);
        $controllers['passwordReset']=new PasswordResetController($pdo,$prefix,$view,$users,$authz,$passwordResets,$mailService,$jobs,$crypto,$limiter);
        $controllers['interactions']=new InteractionController($pdo,$prefix,$view,$users,$authz,$notifications,$interactionService);
        $controllers['webhooks']=new WebhookController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$webhookService);
        $controllers['userAdmin']=new UserAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$userManagementService);
        $controllers['profile']=new ProfileController($pdo,$prefix,$view,$users,$authz,$notifications,$sessionService);
        $controllers['presence']=new PresenceController($pdo,$prefix,$view,$users,$authz,$notifications,$presenceService);
        $controllers['contentAdmin']=new ContentAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$contentHealthService);
        $controllers['securityDashboard']=new SecurityDashboardController($pdo,$prefix,$view,$users,$authz,$notifications);
        $controllers['pageOperations']=new PageOperationController($pdo,$prefix,$view,$users,$authz,$notifications,$pages,$spaces,$pageOperationService);
        $controllers['templates']=new TemplateController($pdo,$prefix,$view,$users,$authz,$notifications,$templateService,$spaces);
        $controllers['spaceAdmin']=new SpaceAdminController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$pages,$spaceManagementService);
        $controllers['importExport']=new ImportExportController($pdo,$prefix,$view,$users,$authz,$notifications,$importExportService);
        $controllers['backup']=new BackupController($pdo,$prefix,$view,$users,$authz,$notifications,$backupService);
        $controllers['tree']=new TreeController($pdo,$prefix,$view,$users,$authz,$notifications,$spaces,$pages);

        $router=new Router();
        RouteRegistrar::register($router,$controllers);
        $result=$router->dispatch($request);
        if($result===null&&http_response_code()===404){
            echo $view->render('errors/404.php',['currentUser'=>isset($_SESSION['user_id'])?$users->find((int)$_SESSION['user_id']):null,'authz'=>$authz,'url'=>Url::class,'notificationCount'=>$notifications->unreadCount((int)($_SESSION['user_id']??0)),'requestId'=>IMWIKI_REQUEST_ID]);
        }
    }

    private function handlePendingMigrations(Request $request,\PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,AuthService $authService,RateLimiter $limiter,TotpService $totp,SessionService $sessions):void
    {
        $auth=new AuthController($pdo,$prefix,$view,$users,$authz,$authService,$limiter,$totp,$sessions);
        $path=$request->path();
        if(in_array($path,['/login','/logout','/login/2fa'],true)){
            $router=new Router();$router->get('/login',[$auth,'loginForm']);$router->post('/login',[$auth,'login']);$router->get('/login/2fa',[$auth,'twoFactorForm']);$router->post('/login/2fa',[$auth,'twoFactorVerify']);$router->post('/logout',[$auth,'logout']);$router->dispatch($request);return;
        }
        $uid=(int)($_SESSION['user_id']??0);
        if($uid<=0||!$users->find($uid))Response::redirect(Url::to('/login'));
        if($authz->isAdmin($uid))Response::redirect(Url::to('/upgrade.php'));
        http_response_code(503);
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Aktualizacja wymagana</title><link rel="stylesheet" href="'.htmlspecialchars(Url::to('/public/assets/app.css'),ENT_QUOTES,'UTF-8').'"><main class="installer"><div class="card"><h1>Wymagana aktualizacja bazy danych</h1><p>Administrator musi zakończyć migracje przed dalszym użyciem aplikacji.</p><p class="muted">Reference: '.htmlspecialchars(IMWIKI_REQUEST_ID,ENT_QUOTES,'UTF-8').'</p></div></main></html>';
    }

    private function registerDeferredWork(SchedulerService $scheduler,JobRunner $runner):void
    {
        register_shutdown_function(static function()use($scheduler,$runner):void{
            if(function_exists('fastcgi_finish_request'))@fastcgi_finish_request();
            try{$scheduler->opportunistic();$runner->run(3);}catch(Throwable){}
        });
    }

    private function renderUnhandledError(Throwable $e):void
    {
        (new Logger($this->root.'/storage/logs',(bool)Config::get('app.debug',false)))->error('Unhandled exception',['request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,'exception'=>get_class($e),'message'=>$e->getMessage()]);
        http_response_code(500);
        $ref=defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:'unknown';
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Błąd imWiki</title><link rel="stylesheet" href="'.htmlspecialchars(Url::to('/public/assets/app.css'),ENT_QUOTES,'UTF-8').'"><main class="installer"><div class="card"><h1>Wystąpił błąd aplikacji.</h1><p>Szczegóły techniczne zapisano w logu serwera.</p><p class="muted">Reference: '.htmlspecialchars($ref,ENT_QUOTES,'UTF-8').'</p></div></main></html>';
    }
}
