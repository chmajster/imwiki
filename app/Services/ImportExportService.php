<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Security\Authorization;
use PDO;
use RuntimeException;
use ZipArchive;

final class ImportExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly PageRepository $pages,
        private readonly SpaceRepository $spaces,
        private readonly Authorization $authz,
        private readonly PageService $pageService,
        private readonly MarkdownService $markdown,
        private readonly string $root,
    ){}

    public function pageMarkdown(int $pageId,int $userId):array
    {
        $page=$this->requireView($pageId,$userId);
        return ['name'=>$this->safeName((string)$page['title']).'.md','content'=>$this->markdown->exportDocument($page,$this->labels($pageId))];
    }

    public function pageHtml(int $pageId,int $userId):array
    {
        $page=$this->requireView($pageId,$userId);$title=htmlspecialchars((string)$page['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$body=(string)$page['content'];
        $html='<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{font:16px/1.6 system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px}pre{overflow:auto;padding:12px;background:#f4f4f4}img{max-width:100%}table{border-collapse:collapse}td,th{border:1px solid #bbb;padding:6px}</style></head><body><h1>'.$title.'</h1>'.$body.'</body></html>';
        return ['name'=>$this->safeName((string)$page['title']).'.html','content'=>$html];
    }

    public function spaceZip(string $key,int $userId):string
    {
        $space=$this->spaces->findByKey($key);if(!$space||!$this->authz->canViewSpace($userId,(int)$space['id']))throw new RuntimeException('FORBIDDEN');if(!class_exists(ZipArchive::class))throw new RuntimeException('ZIP_UNAVAILABLE');
        $tree=$this->pages->treeVisible((int)$space['id'],$userId,$this->authz->isAdmin($userId));$byId=[];foreach($tree as $row)$byId[(int)$row['id']]=$row;
        $private=$this->root.'/storage/private';if(!is_dir($private)&&!@mkdir($private,0770,true)&&!is_dir($private))throw new RuntimeException('STORAGE');$tmp=tempnam($private,'export-');if($tmp===false)throw new RuntimeException('TEMP');
        $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true){@unlink($tmp);throw new RuntimeException('ZIP');}
        try{
            foreach($tree as $row){
                $page=$this->pages->find((int)$row['id']);if(!$page||!$this->authz->canViewPage($userId,$page))continue;
                $basePath=$this->treePath((int)$row['id'],$byId);$path=$basePath.'/'.$this->safeName((string)$page['title']).'.md';
                $zip->addFromString(ltrim($path,'/'),$this->markdown->exportDocument($page,$this->labels((int)$page['id'])));
                $attachments=$this->pdo->prepare("SELECT original_name,stored_name FROM `{$this->prefix}attachments` WHERE page_id=? AND deleted_at IS NULL");$attachments->execute([(int)$page['id']]);
                foreach($attachments->fetchAll() as $attachment){$stored=(string)$attachment['stored_name'];if(!preg_match('/^[a-f0-9]{48}$/',$stored))continue;$file=$this->root.'/storage/uploads/'.$stored;if(is_file($file))$zip->addFile($file,ltrim($basePath.'/attachments/'.$this->safeName((string)$attachment['original_name']),'/'));}
            }
            $zip->addFromString('metadata.json',json_encode(['space_key'=>$space['space_key'],'space_name'=>$space['name'],'exported_at'=>gmdate('c'),'format_version'=>1],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $zip->close();return $tmp;
        }catch(\Throwable $e){$zip->close();@unlink($tmp);throw $e;}
    }

    public function importMarkdown(string $key,array $file,int $userId):int
    {
        $space=$this->requireCreateSpace($key,$userId);$raw=$this->uploadedText($file,2_000_000,['md','markdown']);$doc=$this->markdown->parseDocument($raw);$title=trim((string)($doc['meta']['title']??pathinfo((string)$file['name'],PATHINFO_FILENAME)));if($title==='')$title='Zaimportowana strona';
        $id=$this->pageService->create((int)$space['id'],null,$title,(string)$doc['html'],$userId);$this->applyImportedMetadata($id,(array)$doc['meta'],$userId);return $id;
    }

    public function importZip(string $key,array $file,int $userId):array
    {
        $space=$this->requireCreateSpace($key,$userId);if(!class_exists(ZipArchive::class))throw new RuntimeException('ZIP_UNAVAILABLE');$tmp=(string)($file['tmp_name']??'');
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||(int)($file['size']??0)>25_000_000)throw new \InvalidArgumentException('Nieprawidłowy ZIP.');
        $zip=new ZipArchive();if($zip->open($tmp)!==true)throw new \InvalidArgumentException('Nie można otworzyć ZIP.');$entries=[];$total=0;
        for($i=0;$i<$zip->numFiles&&count($entries)<500;$i++){
            $stat=$zip->statIndex($i);$name=str_replace('\\','/',(string)($stat['name']??''));if($name===''||str_starts_with($name,'/')||str_contains('/'.$name.'/','/../')||str_contains($name,"\0"))continue;if(!preg_match('/\.(md|markdown)$/i',$name))continue;
            $size=(int)($stat['size']??0);$total+=$size;if($size>2_000_000||$total>20_000_000){$zip->close();throw new \InvalidArgumentException('Archiwum jest zbyt duże.');}$entries[$name]=(string)$zip->getFromIndex($i);
        }
        $zip->close();uksort($entries,static fn(string $a,string $b):int=>substr_count($a,'/')<=>substr_count($b,'/'));
        $parents=[];$created=[];
        foreach($entries as $name=>$raw){$doc=$this->markdown->parseDocument($raw);$dir=trim(dirname($name),'.\/');$parent=$parents[$dir]??null;$title=trim((string)($doc['meta']['title']??pathinfo($name,PATHINFO_FILENAME)));$id=$this->pageService->create((int)$space['id'],$parent,$title!==''?$title:'Zaimportowana strona',(string)$doc['html'],$userId);$this->applyImportedMetadata($id,(array)$doc['meta'],$userId);$created[]=$id;$parents[trim(preg_replace('/\.(md|markdown)$/i','',$name),'/')]=$id;}
        return $created;
    }

    private function applyImportedMetadata(int $pageId,array $meta,int $actorId):void
    {
        $this->pdo->beginTransaction();
        try{
            $labels=is_array($meta['labels']??null)?$meta['labels']:[];$labels=array_slice(array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$labels),static fn(string $v):bool=>$v!==''&&mb_strlen($v)<=100))),0,50);
            if($labels){
                $labelInsert=$this->pdo->prepare("INSERT INTO `{$this->prefix}labels` (name) VALUES (?) ON DUPLICATE KEY UPDATE name=VALUES(name)");$labelId=$this->pdo->prepare("SELECT id FROM `{$this->prefix}labels` WHERE name=? LIMIT 1");$link=$this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}page_labels` (page_id,label_id) VALUES (?,?)");
                foreach($labels as $label){$labelInsert->execute([$label]);$labelId->execute([$label]);$id=(int)$labelId->fetchColumn();if($id>0)$link->execute([$pageId,$id]);}
            }
            $review=trim((string)($meta['review_date']??''));if($review!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$review))$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET review_date=? WHERE id=?")->execute([$review,$pageId]);

            $workflow=(string)($this->pdo->query("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='workflow.status_enabled' LIMIT 1")->fetchColumn()?:'0')==='1';
            $status=mb_strtolower(trim((string)($meta['status']??'')));if(!$workflow&&in_array($status,['draft','published'],true))$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET status=? WHERE id=?")->execute([$status,$pageId]);

            $ownerName=trim((string)($meta['owner']??''));if($ownerName!==''){
                $page=$this->pages->find($pageId);if($page&&$this->authz->canManagePageRestrictions($actorId,$page)){$owner=$this->pdo->prepare("SELECT id FROM `{$this->prefix}users` WHERE username=? AND status='active' AND deleted_at IS NULL LIMIT 1");$owner->execute([$ownerName]);$ownerId=(int)($owner->fetchColumn()?:0);if($ownerId>0)$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET owner_id=? WHERE id=?")->execute([$ownerId,$pageId]);}
            }
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function requireView(int $id,int $uid):array{$page=$this->pages->find($id);if(!$page||!$this->authz->canViewPage($uid,$page))throw new RuntimeException('FORBIDDEN');return $page;}
    private function requireCreateSpace(string $key,int $uid):array{$space=$this->spaces->findByKey($key);if(!$space||!$this->authz->canCreatePage($uid,(int)$space['id']))throw new RuntimeException('FORBIDDEN');return $space;}
    private function labels(int $pageId):array{$stmt=$this->pdo->prepare("SELECT l.name FROM `{$this->prefix}labels` l JOIN `{$this->prefix}page_labels` pl ON pl.label_id=l.id WHERE pl.page_id=? ORDER BY l.name");$stmt->execute([$pageId]);return$stmt->fetchAll(PDO::FETCH_COLUMN);}
    private function uploadedText(array $file,int $max,array $extensions):string{$tmp=(string)($file['tmp_name']??'');$name=(string)($file['name']??'');if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||(int)($file['size']??0)>$max||!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),$extensions,true))throw new \InvalidArgumentException('Nieprawidłowy plik.');$raw=file_get_contents($tmp);if($raw===false||str_contains($raw,"\0"))throw new \InvalidArgumentException('Nieprawidłowa zawartość.');return$raw;}
    private function treePath(int $id,array $byId):string{$parts=[];$seen=[];$cursor=$id;while(isset($byId[$cursor])&&!isset($seen[$cursor])&&count($parts)<50){$seen[$cursor]=1;$row=$byId[$cursor];$parts[]=$this->safeName((string)$row['title']);$cursor=(int)($row['parent_id']??0);if($cursor===0)break;}array_pop($parts);return implode('/',array_reverse($parts));}
    private function safeName(string $name):string{$name=trim(preg_replace('/[\\\\\/<>:"|?*\x00-\x1F]+/u','-',basename($name))??'page');return mb_substr($name!==''?$name:'page',0,120);}
}
