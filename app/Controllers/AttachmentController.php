<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class AttachmentController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly AttachmentService $attachments)
    {parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function upload(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$pageId=(int)$params['id'];$ajax=strtolower((string)$request->header('X-Requested-With',''))==='xmlhttprequest';
        try{$files=$this->normalizeFiles($_FILES['attachments']??($_FILES['attachment']??[]));if(!$files)throw new \InvalidArgumentException('Brak pliku.');$created=[];foreach(array_slice($files,0,20) as $file){$id=$this->attachments->storeUploaded($pageId,$file,$uid);$v=$this->attachments->resolveCurrent($id,$uid);$created[]=['id'=>$id,'name'=>$v['original_name'],'mime'=>$v['mime_type'],'download_url'=>Url::to('/attachments/'.$id.'/download'),'preview_url'=>str_starts_with((string)$v['mime_type'],'image/')?Url::to('/attachments/'.$id.'/preview'):null];$this->audit($request,'attachment.uploaded','attachment',$id,'Dodano wersję załącznika');}if($ajax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'attachments'=>$created],JSON_UNESCAPED_UNICODE);return;}Response::redirect(Url::to('/pages/'.$pageId.'#attachments'));}
        catch(\InvalidArgumentException $e){if($ajax){http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);return;}Response::redirect(Url::to('/pages/'.$pageId.'?upload=blocked#attachments'));}
        catch(\Throwable){if($ajax){http_response_code(500);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Nie udało się przesłać pliku.'],JSON_UNESCAPED_UNICODE);return;}Response::redirect(Url::to('/pages/'.$pageId.'?upload=error#attachments'));}
    }

    public function download(Request $request,array $params): never
    {
        $uid=$this->requireAuth();try{$this->attachments->stream($this->attachments->resolveCurrent((int)$params['id'],$uid));}catch(\Throwable){http_response_code(404);exit;}
    }

    public function versions(Request $request,array $params): void
    {
        $uid=$this->requireAuth();try{$data=$this->attachments->versions((int)$params['id'],$uid);echo $this->view->render('attachments/versions.php',$this->common($data));}catch(\Throwable){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());}
    }

    public function downloadVersion(Request $request,array $params): never
    {
        $uid=$this->requireAuth();try{$this->attachments->stream($this->attachments->resolveVersion((int)$params['versionId'],$uid));}catch(\Throwable){http_response_code(404);exit;}
    }

    public function preview(Request $request,array $params): never
    {
        $uid=$this->requireAuth();try{$this->attachments->streamInlineImage($this->attachments->resolveCurrent((int)$params['id'],$uid));}catch(\Throwable){http_response_code(404);exit;}
    }

    private function normalizeFiles(array $files): array
    {
        if(!$files)return[];if(!is_array($files['name']??null))return isset($files['name'])?[$files]:[];$out=[];$count=count($files['name']);for($i=0;$i<$count;$i++)$out[]=['name'=>$files['name'][$i]??'plik','type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];return$out;
    }
}
