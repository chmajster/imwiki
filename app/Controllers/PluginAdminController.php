<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Plugins\PluginManager;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\NotificationService;
use ImWiki\Support\FeatureFlags;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class PluginAdminController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly PluginManager $plugins,private readonly FeatureFlags $flags){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function index(Request $request):void
    {
        $uid=$this->requireAdmin();
        echo $this->view->render('admin/plugins.php',$this->common([
            'plugins'=>$this->plugins->list(),
            'safeMode'=>$this->plugins->safeMode(),
            'featureEnabled'=>$this->flags->enabled('plugins'),
            'message'=>(string)$request->input('message',''),
        ]));
    }

    public function install(Request $request):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);
        try{
            $file=$_FILES['plugin_zip']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \RuntimeException('Plugin ZIP is required.');
            $manifest=$this->plugins->installZip((string)$file['tmp_name']);
            $this->audit($request,'plugin.installed','plugin',null,'Zainstalowano plugin','security','warning',['plugin_id'=>$manifest['id'],'version'=>$manifest['version']]);
            Response::redirect(Url::to('/admin/plugins?message=installed'));
        }catch(\Throwable){Response::redirect(Url::to('/admin/plugins?message=install_error'));}
    }

    public function toggle(Request $request,array $params):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);$id=(string)($params['id']??'');$enabled=!empty($request->input('enabled'));
        try{$this->plugins->setEnabled($id,$enabled);$this->audit($request,$enabled?'plugin.enabled':'plugin.disabled','plugin',null,$enabled?'Włączono plugin':'Wyłączono plugin','security','warning',['plugin_id'=>$id]);}catch(\Throwable){}
        Response::redirect(Url::to('/admin/plugins'));
    }

    public function uninstall(Request $request,array $params):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);$id=(string)($params['id']??'');
        try{$this->plugins->uninstall($id);$this->audit($request,'plugin.uninstalled','plugin',null,'Odinstalowano plugin','security','warning',['plugin_id'=>$id]);}catch(\Throwable){}
        Response::redirect(Url::to('/admin/plugins'));
    }

    public function feature(Request $request):void
    {
        $uid=$this->requireAdmin();$this->csrf($request);$this->flags->set('plugins',!empty($request->input('enabled')),$uid);Response::redirect(Url::to('/admin/plugins'));
    }

    private function requireAdmin():int
    {
        $uid=$this->requireAuth();if(!$this->authz->canAdmin($uid,'admin.plugins')&&!$this->authz->can($uid,'plugins.manage')){http_response_code(403);exit;}return$uid;
    }
}
