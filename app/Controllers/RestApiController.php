<?php
declare(strict_types=1);
namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\ApiTokenService;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\PageService;

final class RestApiController
{
    public function __construct(private readonly ApiTokenService $tokens,private readonly Authorization $authz,private readonly SpaceRepository $spaces,private readonly PageRepository $pages,private readonly PageService $pageService,private readonly AttachmentService $attachments){}

    public function spaces(Request $request):never
    {
        $auth=$this->auth($request,'spaces:read');$uid=(int)$auth['user_id'];$items=$this->spaces->allVisible($uid,$this->authz->isAdmin($uid));
        Response::json(['items'=>array_map(static fn(array $s):array=>['id'=>(int)$s['id'],'key'=>$s['space_key'],'name'=>$s['name'],'description'=>$s['description'],'visibility'=>$s['visibility'],'archived'=>$s['archived_at']!==null],$items)]);
    }

    public function page(Request $request,array $params):never
    {
        $auth=$this->auth($request,'pages:read');$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canViewPage((int)$auth['user_id'],$page))Response::json(['error'=>'not_found'],404);
        Response::json(['page'=>$this->pagePayload($page)]);
    }

    public function createPage(Request $request):never
    {
        $auth=$this->auth($request,'pages:write');$uid=(int)$auth['user_id'];$data=$request->json();$spaceId=(int)($data['space_id']??0);$parent=(int)($data['parent_id']??0);
        if($spaceId<=0||!$this->authz->canCreatePage($uid,$spaceId))Response::json(['error'=>'forbidden'],403);
        try{$id=$this->pageService->create($spaceId,$parent>0?$parent:null,(string)($data['title']??''),(string)($data['content']??''),$uid);$page=$this->pages->find($id);Response::json(['page'=>$this->pagePayload($page?:[])],201);}catch(\InvalidArgumentException $e){Response::json(['error'=>'validation','message'=>$e->getMessage()],422);}
    }

    public function updatePage(Request $request,array $params):never
    {
        $auth=$this->auth($request,'pages:write');$uid=(int)$auth['user_id'];$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canEditPage($uid,$page))Response::json(['error'=>'not_found'],404);$data=$request->json();
        $base=(int)($data['base_version']??0);if($base<=0)Response::json(['error'=>'validation','message'=>'base_version is required'],422);$parent=array_key_exists('parent_id',$data)?(int)$data['parent_id']:(int)($page['parent_id']??0);
        try{$version=$this->pageService->update((int)$page['id'],(string)($data['title']??$page['title']),(string)($data['content']??$page['content']),$base,$parent>0?$parent:null,$uid,(string)($data['change_comment']??'API update'));Response::json(['page'=>$this->pagePayload($this->pages->find((int)$page['id'])?:[]),'version'=>$version]);}catch(\RuntimeException $e){if($e->getMessage()==='CONFLICT')Response::json(['error'=>'conflict','current_version'=>(int)$page['version_no']],409);Response::json(['error'=>'update_failed'],409);}catch(\InvalidArgumentException $e){Response::json(['error'=>'validation','message'=>$e->getMessage()],422);}
    }

    public function search(Request $request):never
    {
        $auth=$this->auth($request,'pages:read');$uid=(int)$auth['user_id'];$q=trim((string)$request->input('q',''));$items=[];foreach($this->pages->search($q,50) as $row){$page=$this->pages->find((int)$row['id']);if($page&&$this->authz->canViewPage($uid,$page))$items[]=['id'=>(int)$row['id'],'title'=>$row['title'],'space'=>$row['space_name'],'space_key'=>$row['space_key'],'score'=>(float)$row['score']];}Response::json(['query'=>$q,'items'=>$items]);
    }

    public function uploadAttachment(Request $request,array $params):never
    {
        $auth=$this->auth($request,'attachments:write');$uid=(int)$auth['user_id'];$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canAttachPage($uid,$page))Response::json(['error'=>'not_found'],404);
        try{$id=$this->attachments->storeUploaded((int)$page['id'],$_FILES['attachment']??[],$uid);Response::json(['attachment_id'=>$id],201);}catch(\InvalidArgumentException $e){Response::json(['error'=>'validation','message'=>$e->getMessage()],422);}
    }

    public function downloadAttachment(Request $request,array $params):never
    {
        $auth=$this->auth($request,'attachments:read');try{$this->attachments->stream($this->attachments->resolveCurrent((int)$params['id'],(int)$auth['user_id']));}catch(\Throwable){http_response_code(404);exit;}
    }

    private function auth(Request $request,string $scope):array
    {
        $auth=$this->tokens->authenticate($request->header('Authorization'));if(!$auth){header('WWW-Authenticate: Bearer');Response::json(['error'=>'unauthorized'],401);}if(!ApiTokenService::hasScope($auth,$scope))Response::json(['error'=>'insufficient_scope','required_scope'=>$scope],403);return $auth;
    }

    private function pagePayload(array $page):array
    {
        if(!$page)return[];return ['id'=>(int)$page['id'],'space_id'=>(int)$page['space_id'],'parent_id'=>$page['parent_id']!==null?(int)$page['parent_id']:null,'title'=>$page['title'],'slug'=>$page['slug'],'content'=>$page['content'],'status'=>$page['status'],'version'=>(int)$page['version_no'],'owner_id'=>(int)($page['owner_id']??$page['author_id']),'updated_at'=>$page['updated_at']];
    }
}
