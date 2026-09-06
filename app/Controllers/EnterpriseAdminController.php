<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Audit\AuditService;
use ImWiki\Database\Migrator;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\IpAccessPolicy;
use ImWiki\Security\SecurityPolicyService;
use ImWiki\Services\ContentGovernanceService;
use ImWiki\Services\EnterpriseSpaceService;
use ImWiki\Services\HealthService;
use ImWiki\Services\JobQueueService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PermissionDiagnosticsService;
use ImWiki\Services\PropertySchemaService;
use ImWiki\Storage\StorageManager;
use ImWiki\Support\FeatureFlags;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class EnterpriseAdminController extends BaseController
{
    public function __construct(
        PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,
        private readonly EnterpriseSpaceService $spacesEnterprise,
        private readonly ContentGovernanceService $governance,
        private readonly PropertySchemaService $schemas,
        private readonly StorageManager $storage,
        private readonly JobQueueService $jobs,
        private readonly SecurityPolicyService $securityPolicies,
        private readonly IpAccessPolicy $ipPolicy,
        private readonly FeatureFlags $flags,
        private readonly AuditService $auditService,
        private readonly HealthService $health,
        private readonly PermissionDiagnosticsService $permissionDiagnostics,
        private readonly Migrator $migrator,
    ){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function index(Request $request):void
    {
        $uid=$this->requireEnterpriseAdmin();$tab=(string)$request->input('tab','overview');
        $auditFilters=['user_id'=>$request->input('audit_user'),'action'=>$request->input('audit_action'),'category'=>$request->input('audit_category'),'resource_type'=>$request->input('audit_resource'),'severity'=>$request->input('audit_severity'),'ip'=>$request->input('audit_ip'),'date_from'=>$request->input('audit_from'),'date_to'=>$request->input('audit_to')];
        $data=[
            'tab'=>$tab,
            'health'=>$this->health->detailed(),
            'features'=>$this->flags->all(),
            'spaceTree'=>$this->spacesEnterprise->listWithHierarchy($uid),
            'categories'=>$this->spacesEnterprise->categories($uid),
            'ownership'=>$this->authz->can($uid,'content.governance')?$this->governance->ownershipReport($uid):['pages'=>[],'spaces'=>[]],
            'labels'=>$this->governance->labels(),
            'classifications'=>$this->governance->classifications(),
            'schemas'=>$this->schemas->schemas($uid),
            'storage'=>$this->storage->dashboard(),
            'cleanupCandidates'=>$this->storage->cleanupCandidates(),
            'jobsDashboard'=>$this->jobs->dashboard(150),
            'securityPolicies'=>$this->securityPolicies->all(),
            'trustedProxies'=>$this->ipPolicy->trustedProxies(),
            'migrationPlan'=>$this->migrator->dryRun(),
            'audit'=>$this->authz->canAdmin($uid,'admin.audit')?$this->auditService->query(array_filter($auditFilters,static fn($v)=>$v!==null&&$v!==''),max(1,(int)$request->input('audit_page',1)),50):['items'=>[],'total'=>0,'page'=>1,'next'=>null],
            'auditFilters'=>$auditFilters,
            'aclResult'=>null,
            'message'=>(string)$request->input('message',''),
        ];
        if($request->input('acl_user')&&$request->input('acl_page')){try{$data['aclResult']=$this->permissionDiagnostics->diagnose($uid,(int)$request->input('acl_user'),(int)$request->input('acl_page'));}catch(\Throwable){$data['message']='acl_error';}}
        echo $this->view->render('admin/enterprise.php',$this->common($data));
    }

    public function feature(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);$key=(string)$request->input('key');try{$this->flags->set($key,!empty($request->input('enabled')),$uid,(int)$request->input('space_id')?:null);$this->audit($request,'feature_flag.changed','feature_flag',null,'Zmieniono feature flag','administration','warning',['key'=>$key,'enabled'=>!empty($request->input('enabled'))]);$this->back('features','saved');}catch(\Throwable){$this->back('features','error');}}
    public function createCategory(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$this->spacesEnterprise->createCategory($uid,(string)$request->input('name'));$this->back('spaces','category_saved');}catch(\Throwable){$this->back('spaces','error');}}
    public function setSpaceLifecycle(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$this->spacesEnterprise->setLifecycle($uid,(int)$request->input('space_id'),(string)$request->input('lifecycle'),(string)$request->input('review_date')?:null,(string)$request->input('archive_date')?:null,(int)$request->input('retention_policy_id')?:null);$this->back('spaces','lifecycle_saved');}catch(\Throwable){$this->back('spaces','error');}}

    public function saveClassification(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$this->governance->saveClassification($uid,(int)$request->input('id')?:null,(string)$request->input('key'),(string)$request->input('name'),(int)$request->input('sort_order',0),!empty($request->input('is_public')),!empty($request->input('enabled')));$this->back('governance','classification_saved');}catch(\Throwable){$this->back('governance','error');}}
    public function renameLabel(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$this->governance->renameLabel((int)$request->input('id'),(string)$request->input('name'),$uid);$this->back('governance','label_saved');}catch(\Throwable){$this->back('governance','error');}}
    public function deleteLabel(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$this->governance->deleteLabel((int)$request->input('id'),$uid);$this->back('governance','label_deleted');}catch(\Throwable){$this->back('governance','error');}}
    public function transferOwnership(Request $request):void{$uid=$this->requireEnterpriseAdmin();$this->csrf($request);$ids=array_filter(array_map('intval',preg_split('/[\s,]+/',(string)$request->input('page_ids',''))?:[]));try{$result=$this->governance->transferOwnership($ids,(int)$request->input('owner_id'),$uid);$_SESSION['enterprise_bulk_result']=$result;$this->back('governance','ownership_transferred');}catch(\Throwable){$this->back('governance','error');}}

    public function createSchema(Request $request):void
    {
        $uid=$this->requireEnterpriseAdmin();$this->csrf($request);try{$raw=json_decode((string)$request->input('fields_json','[]'),true,512,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new \RuntimeException('Invalid fields.');$this->schemas->create($uid,(int)$request->input('space_id')?:null,(string)$request->input('name'),(string)$request->input('description'),$raw);$this->back('schemas','schema_saved');}catch(\Throwable){$this->back('schemas','error');}
    }

    public function saveSecurity(Request $request):void
    {
        $uid=$this->requireModule('admin.security');$this->csrf($request);try{$this->securityPolicies->save([
            'security.session_lifetime'=>$request->input('session_lifetime'),
            'security.idle_timeout'=>$request->input('idle_timeout'),
            'security.max_login_attempts'=>$request->input('max_login_attempts'),
            'security.lockout_seconds'=>$request->input('lockout_seconds'),
            'security.min_password_length'=>$request->input('min_password_length'),
            'security.password_reset_expiry'=>$request->input('password_reset_expiry'),
            'security.trusted_device_days'=>$request->input('trusted_device_days'),
            'security.require_2fa'=>!empty($request->input('require_2fa')),
            'security.hsts'=>!empty($request->input('hsts')),
            'security.csp_report_only'=>!empty($request->input('csp_report_only')),
            'system.read_only'=>!empty($request->input('read_only')),
            'security.allowed_auth_methods'=>(array)$request->input('allowed_auth_methods',[]),
            'security.trusted_proxies'=>(string)$request->input('trusted_proxies',''),
        ]);$this->audit($request,'security.policy_changed','settings',null,'Zmieniono politykę bezpieczeństwa','security','warning');$this->back('security','saved');}catch(\Throwable){$this->back('security','error');}
    }

    public function addIpRule(Request $request):void{$uid=$this->requireModule('admin.security');$this->csrf($request);try{$this->ipPolicy->saveRule((string)$request->input('scope'),(string)$request->input('action'),(string)$request->input('cidr'),(string)$request->input('description'),$uid);$this->back('security','ip_saved');}catch(\Throwable){$this->back('security','error');}}
    public function retryJob(Request $request,array $params):void{$this->requireModule('jobs.manage');$this->csrf($request);$this->jobs->retry((int)($params['id']??0));$this->back('jobs','retried');}
    public function discardJob(Request $request,array $params):void{$this->requireModule('jobs.manage');$this->csrf($request);$this->jobs->discard((int)($params['id']??0));$this->back('jobs','discarded');}
    public function verifyStorage(Request $request):void{$this->requireModule('storage.manage');$this->csrf($request);$_SESSION['storage_verify_result']=$this->storage->verifyAttachments(2000);$this->back('storage','verified');}
    public function cleanupOrphan(Request $request):void{$this->requireModule('storage.manage');$this->csrf($request);$ok=$this->storage->cleanupOrphanFile((string)$request->input('name'));$this->back('storage',$ok?'orphan_deleted':'orphan_kept');}

    public function auditExport(Request $request):never
    {
        $uid=$this->requireModule('admin.audit');$format=in_array((string)$request->input('format'),['csv','json'],true)?(string)$request->input('format'):'csv';$filters=['user_id'=>$request->input('user_id'),'action'=>$request->input('action'),'category'=>$request->input('category'),'resource_type'=>$request->input('resource_type'),'severity'=>$request->input('severity'),'ip'=>$request->input('ip'),'date_from'=>$request->input('date_from'),'date_to'=>$request->input('date_to')];$result=$this->auditService->export(array_filter($filters,static fn($v)=>$v!==null&&$v!==''),$format,$uid);header('Content-Type: '.($format==='json'?'application/json':'text/csv').'; charset=utf-8');header('Content-Disposition: attachment; filename="'.basename($result['filename']).'"');header('X-Checksum-SHA256: '.$result['checksum_sha256']);header('Content-Length: '.$result['size_bytes']);readfile($result['path']);exit;
    }

    private function requireEnterpriseAdmin():int{return$this->requireModule('admin.access');}
    private function requireModule(string $permission):int{$uid=$this->requireAuth();if(!$this->authz->canAdmin($uid,$permission)&&!$this->authz->can($uid,$permission)){http_response_code(403);exit;}return$uid;}
    private function back(string $tab,string $message):never{Response::redirect(Url::to('/admin/enterprise?tab='.rawurlencode($tab).'&message='.rawurlencode($message)));}
}
