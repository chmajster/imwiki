<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;

final class AdminController extends BaseController
{
    public function users(Request $request): void
    {
        $uid=$this->requireAuth(); if(!$this->authz->can($uid,'users.manage')) { http_response_code(403); echo $this->view->render('errors/403.php',$this->common()); return; }
        $page=max(1,(int)$request->input('page',1)); echo $this->view->render('admin/users.php',$this->common(['usersList'=>$this->users->paginate($page,25),'page'=>$page]));
    }

    public function system(Request $request): void
    {
        $uid=$this->requireAuth(); if(!$this->authz->can($uid,'administration.access')) { http_response_code(403); echo $this->view->render('errors/403.php',$this->common()); return; }
        $dbVersion=(string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
        $workflowStmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='workflow.status_enabled'");$workflowStmt->execute();$workflowEnabled=(string)($workflowStmt->fetchColumn()?:'0')==='1';
        $shareStmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='sharing.public_enabled'");$shareStmt->execute();$publicSharingEnabled=(string)($shareStmt->fetchColumn()?:'0')==='1';
        $maintenanceStmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='maintenance.enabled'");$maintenanceStmt->execute();$maintenanceEnabled=(string)($maintenanceStmt->fetchColumn()?:'0')==='1';
        $pendingJobs=(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status IN ('pending','running')")->fetchColumn();$failedJobs=(int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}jobs` WHERE status='failed'")->fetchColumn();$disk=@disk_free_space(dirname(__DIR__,2));$diskFree=$disk===false?null:(int)$disk;
        echo $this->view->render('admin/system.php',$this->common(['pendingJobs'=>$pendingJobs,'failedJobs'=>$failedJobs,'diskFree'=>$diskFree,'dbVersion'=>$dbVersion,'phpVersion'=>PHP_VERSION,'server'=>$_SERVER['SERVER_SOFTWARE']??'unknown','upload'=>ini_get('upload_max_filesize'),'storageWritable'=>is_writable(dirname(__DIR__,2).'/storage'),'timezone'=>date_default_timezone_get(),'workflowEnabled'=>$workflowEnabled,'publicSharingEnabled'=>$publicSharingEnabled,'maintenanceEnabled'=>$maintenanceEnabled]));
    }

    public function maintenance(Request $request): void
    {
        $uid=$this->requireAuth();if(!$this->authz->can($uid,'administration.access')){http_response_code(403);return;}$this->csrf($request);$enabled=(string)$request->input('enabled','0')==='1'?'1':'0';$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES ('maintenance.enabled',?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");$stmt->execute([$enabled]);$this->audit($request,'system.maintenance_changed','setting',null,'Maintenance mode '.($enabled==='1'?'enabled':'disabled'));\ImWiki\Http\Response::redirect(\ImWiki\Support\Url::to('/admin/system'));
    }
}
