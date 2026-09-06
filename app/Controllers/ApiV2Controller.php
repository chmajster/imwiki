<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Audit\AuditService;
use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\RateLimiter;
use ImWiki\Services\ApiTokenService;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\PageService;
use ImWiki\Services\SearchService;
use ImWiki\Support\FeatureFlags;
use PDO;

final class ApiV2Controller
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly ApiTokenService $tokens,
        private readonly Authorization $authz,
        private readonly SpaceRepository $spaces,
        private readonly PageRepository $pages,
        private readonly PageService $pageService,
        private readonly AttachmentService $attachments,
        private readonly SearchService $search,
        private readonly RateLimiter $limiter,
        private readonly AuditService $audit,
        private readonly FeatureFlags $flags,
    ){}

    public function spaces(Request $request):never
    {
        $auth=$this->authorize($request,'spaces:read');$uid=(int)$auth['user_id'];$cursor=max(0,(int)$request->input('cursor',0));$limit=max(1,min(100,(int)$request->input('limit',50)));
        $stmt=$this->pdo->prepare("SELECT id,name,space_key,description,visibility,lifecycle,owner_id,updated_at FROM `{$this->prefix}spaces` WHERE deleted_at IS NULL AND id>? ORDER BY id LIMIT ".($limit*4));$stmt->execute([$cursor]);$items=[];$next=null;foreach($stmt->fetchAll() as $row){if(!$this->authz->canViewSpace($uid,(int)$row['id']))continue;$items[]=$row;$next=(int)$row['id'];if(count($items)>=$limit)break;}$this->ok(['items'=>$items,'meta'=>['limit'=>$limit,'next_cursor'=>$next!==null&&count($items)===$limit?$next:null]]);
    }

    public function page(Request $request,array $params):never
    {
        $auth=$this->authorize($request,'pages:read');$page=$this->pageFor($params['id']??'');if(!$page||!$this->authz->canViewPage((int)$auth['user_id'],$page))$this->error('not_found','Page not found.',404);$this->ok(['data'=>$this->pagePayload($page)]);
    }

    public function createPage(Request $request):never
    {
        $auth=$this->authorize($request,'pages:write');$uid=(int)$auth['user_id'];$data=$request->json();$spaceId=(int)($data['space_id']??0);if($spaceId<=0||!$this->authz->canCreatePage($uid,$spaceId))$this->error('permission_denied','Cannot create a page in this Space.',403);$title=trim((string)($data['title']??''));$content=(string)($data['content']??'');$parent=isset($data['parent_id'])&&$data['parent_id']!==null?(int)$data['parent_id']:null;try{$id=$this->pageService->create($spaceId,$parent,$title,$content,$uid);$page=$this->pages->find($id);$this->auditUse($request,$auth,'api.page_created','page',$id);$this->ok(['data'=>$this->pagePayload($page?:[])],201);}catch(\InvalidArgumentException $e){$this->error('validation_error',$e->getMessage(),422);}catch(\Throwable){$this->error('conflict','Page could not be created.',409);}
    }

    public function updatePage(Request $request,array $params):never
    {
        $auth=$this->authorize($request,'pages:write');$uid=(int)$auth['user_id'];$page=$this->pageFor($params['id']??'');if(!$page||!$this->authz->canEditPage($uid,$page))$this->error('not_found','Page not found.',404);$data=$request->json();$base=(int)($data['base_version']??0);if($base<=0)$this->error('validation_error','base_version is required.',422);$parent=array_key_exists('parent_id',$data)&&$data['parent_id']!==null?(int)$data['parent_id']:($page['parent_id']!==null?(int)$page['parent_id']:null);try{$version=$this->pageService->update((int)$page['id'],(string)($data['title']??$page['title']),(string)($data['content']??$page['content']),$base,$parent,$uid,(string)($data['change_comment']??'API v2 update'));$updated=$this->pages->find((int)$page['id']);$this->auditUse($request,$auth,'api.page_updated','page',(int)$page['id']);$this->ok(['data'=>$this->pagePayload($updated?:[]),'meta'=>['version'=>$version]]);}catch(\RuntimeException $e){if($e->getMessage()==='CONFLICT')$this->error('version_conflict','The page changed since base_version.',409);$this->error('conflict','Page update failed.',409);}catch(\InvalidArgumentException $e){$this->error('validation_error',$e->getMessage(),422);}
    }

    public function search(Request $request):never
    {
        $auth=$this->authorize($request,'pages:read');$q=trim((string)$request->input('q',''));if($q==='')$this->error('validation_error','q is required.',422);$limit=max(1,min(100,(int)$request->input('limit',50)));$rows=$this->search->searchVisible($q,(int)$auth['user_id'],$limit);$items=[];foreach($rows as $row){$items[]=['id'=>(int)$row['id'],'title'=>$row['title'],'status'=>$row['status'],'space_key'=>$row['space_key'],'score'=>(float)($row['score']??0),'excerpt'=>$row['excerpt']??null,'updated_at'=>$row['updated_at']??null];}$this->auditUse($request,$auth,'api.search','search',null);$this->ok(['items'=>$items,'meta'=>['count'=>count($items),'limit'=>$limit]]);
    }

    public function uploadAttachment(Request $request,array $params):never
    {
        $auth=$this->authorize($request,'attachments:write');$page=$this->pageFor($params['id']??'');$uid=(int)$auth['user_id'];if(!$page||!$this->authz->canAttachPage($uid,$page))$this->error('not_found','Page not found.',404);$file=$_FILES['file']??null;if(!is_array($file))$this->error('validation_error','Multipart field file is required.',422);try{$id=$this->attachments->storeUploaded((int)$page['id'],$file,$uid);$this->auditUse($request,$auth,'api.attachment_uploaded','attachment',$id);$this->ok(['data'=>['id'=>$id,'page_id'=>(int)$page['id']]],201);}catch(\InvalidArgumentException $e){$this->error('validation_error',$e->getMessage(),422);}catch(\Throwable){$this->error('upload_failed','Attachment upload failed.',400);}
    }

    private function authorize(Request $request,string $scope):array
    {
        if(!$this->flags->enabled('api'))$this->error('feature_disabled','API is disabled.',503);$auth=$this->tokens->authenticate($request->header('Authorization'));if(!$auth)$this->error('unauthorized','Invalid or expired bearer token.',401);if(!ApiTokenService::hasScope($auth,$scope))$this->error('insufficient_scope','Token does not have required scope: '.$scope,403);$limit=max(10,min(10000,(int)$this->setting('api.rate_limit_per_minute','120')));if($this->limiter->tooManyAttempts('api-v2:'.$auth['token_id'].':'.$request->ip(),$limit,60))$this->error('rate_limited','API rate limit exceeded.',429);header('X-RateLimit-Limit: '.$limit);return$auth;
    }

    private function pageFor(mixed $identifier):?array{return$this->pages->findIdentifier((string)$identifier);}
    private function pagePayload(array $page):array{return['id'=>(int)($page['id']??0),'uuid'=>$page['uuid']??null,'space_id'=>(int)($page['space_id']??0),'space_key'=>$page['space_key']??null,'parent_id'=>$page['parent_id']!==null?(int)$page['parent_id']:null,'title'=>$page['title']??'','slug'=>$page['slug']??'','content'=>$page['content']??'','status'=>$page['status']??'','version_no'=>(int)($page['version_no']??0),'owner_id'=>(int)($page['owner_id']??0),'classification_id'=>$page['classification_id']!==null?(int)$page['classification_id']:null,'updated_at'=>$page['updated_at']??null];}
    private function ok(array $payload,int $status=200):never{$payload['request_id']=defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null;Response::json($payload,$status);}
    private function error(string $code,string $message,int $status):never{if($status===401)header('WWW-Authenticate: Bearer');Response::json(['error'=>['code'=>$code,'message'=>$message,'request_id'=>defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null]],$status);}
    private function auditUse(Request $request,array $auth,string $action,string $type,?int $id):void{try{$this->audit->record((int)$auth['user_id'],$action,'api','info',$type,$id,'API v2 request',$request->ip(),$request->userAgent(),['token_id'=>(int)$auth['token_id']]);}catch(\Throwable){}}
    private function setting(string $key,string $default):string{$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
}
