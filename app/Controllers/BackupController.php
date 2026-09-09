<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\BackupArtifactService;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class BackupController extends BaseController
{
    public function __construct(
        PDO $pdo,
        string $prefix,
        View $view,
        UserRepository $users,
        Authorization $authz,
        ?NotificationService $notifications,
        private readonly BackupArtifactService $backups,
    ){
        parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);
    }

    public function index(Request $request):void
    {
        $uid=$this->requireAdmin();
        echo $this->view->render('admin/backup.php',$this->common([
            'zipAvailable'=>class_exists(\ZipArchive::class),
            'artifacts'=>$this->backups->listRecent(),
            'queuedId'=>(int)$request->input('queued',0),
        ]));
    }

    public function create(Request $request):never
    {
        $uid=$this->requireAdmin();$this->csrf($request);
        try{
            $id=$this->backups->request($uid);
            $this->audit($request,'backup.queued','system',$id,'Dodano backup do kolejki','security','warning');
            Response::redirect(Url::to('/admin/backup?queued='.$id));
        }catch(Throwable $e){
            $this->audit($request,'backup.queue_failed','system',null,'Nie udało się dodać backupu do kolejki','security','critical',['error'=>get_class($e)]);
            Response::redirect(Url::to('/admin/backup?error=queue'));
        }
    }

    public function download(Request $request,array $params):never
    {
        $this->requireAdmin();
        try{$artifact=$this->backups->resolveReady((int)$params['id']);}catch(Throwable){$artifact=null;}
        if(!$artifact){http_response_code(404);exit;}
        $path=(string)$artifact['path'];$size=filesize($path);if($size===false){http_response_code(404);exit;}
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="imwiki-backup-'.(int)$artifact['id'].'.zip"');
        header('Content-Length: '.$size);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);exit;
    }

    public function retry(Request $request,array $params):never
    {
        $uid=$this->requireAdmin();$this->csrf($request);
        $this->backups->retry((int)$params['id'],$uid);
        $this->audit($request,'backup.retried','system',(int)$params['id'],'Ponowiono tworzenie backupu','maintenance','warning');
        Response::redirect(Url::to('/admin/backup'));
    }

    private function requireAdmin():int
    {
        $uid=$this->requireAuth();
        if(!$this->authz->can($uid,'administration.access')){http_response_code(403);exit;}
        return$uid;
    }
}
