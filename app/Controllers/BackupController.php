<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\BackupService;use ImWiki\Services\NotificationService;use ImWiki\View\View;use PDO;use Throwable;
final class BackupController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly BackupService $backup){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function index(Request $r):void{$uid=$this->requireAuth();if(!$this->authz->can($uid,'administration.access')){http_response_code(403);return;}echo $this->view->render('admin/backup.php',$this->common(['zipAvailable'=>class_exists(\ZipArchive::class)]));}
 public function create(Request $r):never{$uid=$this->requireAuth();$this->csrf($r);if(!$this->authz->can($uid,'administration.access')){http_response_code(403);exit;}try{$file=$this->backup->create();$this->audit($r,'backup.created','system',null,'Wygenerowano backup','security','warning');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="imwiki-backup-'.gmdate('Ymd-His').'.zip"');header('Content-Length: '.filesize($file));readfile($file);@unlink($file);exit;}catch(Throwable){http_response_code(500);exit;}}
}
