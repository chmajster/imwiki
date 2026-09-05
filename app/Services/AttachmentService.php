<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class AttachmentService
{
    private const MAX_BYTES = 10_485_760;
    private const BLOCKED_EXTENSIONS = ['php','php3','php4','php5','php7','php8','phtml','phar','cgi','pl','py','sh','bash','exe','com','bat','cmd','msi','svg'];
    private const BLOCKED_MIME = ['application/x-httpd-php','application/x-php','text/x-php','application/x-sh','application/x-executable','application/x-msdownload','image/svg+xml'];

    public function __construct(private readonly PDO $pdo, private readonly string $prefix, private readonly PageRepository $pages, private readonly Authorization $authz, private readonly string $root) {}

    public function storeUploaded(int $pageId, array $file, int $userId): int
    {
        $page=$this->pages->find($pageId);
        if(!$page || !$this->authz->canAttachPage($userId,$page)) throw new \RuntimeException('FORBIDDEN');
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || (int)($file['size']??0)<=0 || (int)$file['size']>self::MAX_BYTES) throw new \InvalidArgumentException('Nieprawidłowy plik lub przekroczony limit 10 MB.');
        $tmp=(string)($file['tmp_name']??'');
        if($tmp==='' || !is_uploaded_file($tmp)) throw new \InvalidArgumentException('Nieprawidłowy plik tymczasowy.');
        $original=mb_substr(basename((string)($file['name']??'plik')),0,255);
        $ext=mb_strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if($ext!=='' && in_array($ext,self::BLOCKED_EXTENSIONS,true)) throw new \InvalidArgumentException('Ten typ pliku jest zablokowany.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if(in_array(mb_strtolower($mime),self::BLOCKED_MIME,true)) throw new \InvalidArgumentException('Ten typ pliku jest zablokowany.');
        $checksum=hash_file('sha256',$tmp) ?: null;
        $stored=bin2hex(random_bytes(24));
        $dir=$this->root.'/storage/uploads';if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Nie można utworzyć katalogu uploads.');
        $dest=$dir.'/'.$stored;
        if(!move_uploaded_file($tmp,$dest)) throw new \RuntimeException('Nie udało się zapisać pliku.');
        @chmod($dest,0640);

        try{
            $this->pdo->beginTransaction();
            $find=$this->pdo->prepare("SELECT * FROM `{$this->prefix}attachments` WHERE page_id=? AND original_name=? AND deleted_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE");
            $find->execute([$pageId,$original]);$attachment=$find->fetch();
            if($attachment){
                $attachmentId=(int)$attachment['id'];$version=(int)$attachment['current_version']+1;
                $ver=$this->pdo->prepare("INSERT INTO `{$this->prefix}attachment_versions` (attachment_id,version_no,stored_name,mime_type,size_bytes,checksum_sha256,uploader_id,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
                $ver->execute([$attachmentId,$version,$stored,$mime,(int)$file['size'],$checksum,$userId]);
                $upd=$this->pdo->prepare("UPDATE `{$this->prefix}attachments` SET stored_name=?,mime_type=?,size_bytes=?,current_version=?,uploader_id=?,created_at=UTC_TIMESTAMP() WHERE id=?");
                $upd->execute([$stored,$mime,(int)$file['size'],$version,$userId,$attachmentId]);
            }else{
                $ins=$this->pdo->prepare("INSERT INTO `{$this->prefix}attachments` (page_id,uploader_id,original_name,stored_name,mime_type,size_bytes,current_version,created_at) VALUES (?,?,?,?,?,?,1,UTC_TIMESTAMP())");
                $ins->execute([$pageId,$userId,$original,$stored,$mime,(int)$file['size']]);$attachmentId=(int)$this->pdo->lastInsertId();
                $ver=$this->pdo->prepare("INSERT INTO `{$this->prefix}attachment_versions` (attachment_id,version_no,stored_name,mime_type,size_bytes,checksum_sha256,uploader_id,created_at) VALUES (?,1,?,?,?,?,?,UTC_TIMESTAMP())");
                $ver->execute([$attachmentId,$stored,$mime,(int)$file['size'],$checksum,$userId]);
            }
            $this->pdo->commit();
            return $attachmentId;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();@unlink($dest);throw $e;}
    }

    public function currentForPage(int $pageId): array
    {
        $stmt=$this->pdo->prepare("SELECT a.*,v.checksum_sha256,CONCAT(u.first_name,' ',u.last_name) uploader_name FROM `{$this->prefix}attachments` a LEFT JOIN `{$this->prefix}attachment_versions` v ON v.attachment_id=a.id AND v.version_no=a.current_version LEFT JOIN `{$this->prefix}users` u ON u.id=a.uploader_id WHERE a.page_id=? AND a.deleted_at IS NULL ORDER BY a.created_at DESC");
        $stmt->execute([$pageId]);return $stmt->fetchAll();
    }

    public function versions(int $attachmentId,int $userId): array
    {
        $attachment=$this->findLogical($attachmentId);if(!$attachment)throw new \RuntimeException('NOT_FOUND');
        $page=$this->pages->find((int)$attachment['page_id']);if(!$page||!$this->authz->canViewPage($userId,$page))throw new \RuntimeException('NOT_FOUND');
        $stmt=$this->pdo->prepare("SELECT v.*,CONCAT(u.first_name,' ',u.last_name) uploader_name FROM `{$this->prefix}attachment_versions` v JOIN `{$this->prefix}users` u ON u.id=v.uploader_id WHERE v.attachment_id=? ORDER BY v.version_no DESC");
        $stmt->execute([$attachmentId]);return ['attachment'=>$attachment,'versions'=>$stmt->fetchAll(),'page'=>$page];
    }

    public function resolveCurrent(int $attachmentId,int $userId): array
    {
        $attachment=$this->findLogical($attachmentId);if(!$attachment)throw new \RuntimeException('NOT_FOUND');
        $page=$this->pages->find((int)$attachment['page_id']);if(!$page||!$this->authz->canViewPage($userId,$page))throw new \RuntimeException('NOT_FOUND');
        $stmt=$this->pdo->prepare("SELECT v.*,a.original_name,a.page_id FROM `{$this->prefix}attachment_versions` v JOIN `{$this->prefix}attachments` a ON a.id=v.attachment_id WHERE a.id=? AND v.version_no=a.current_version AND a.deleted_at IS NULL");
        $stmt->execute([$attachmentId]);$row=$stmt->fetch();if(!$row)throw new \RuntimeException('NOT_FOUND');return $row;
    }

    public function resolveVersion(int $versionId,int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT v.*,a.original_name,a.page_id FROM `{$this->prefix}attachment_versions` v JOIN `{$this->prefix}attachments` a ON a.id=v.attachment_id WHERE v.id=? AND a.deleted_at IS NULL");
        $stmt->execute([$versionId]);$row=$stmt->fetch();if(!$row)throw new \RuntimeException('NOT_FOUND');
        $page=$this->pages->find((int)$row['page_id']);if(!$page||!$this->authz->canViewPage($userId,$page))throw new \RuntimeException('NOT_FOUND');return $row;
    }

    public function stream(array $version): never
    {
        $stored=(string)$version['stored_name'];
        if(!preg_match('/^[a-f0-9]{48}$/',$stored)){http_response_code(404);exit;}
        $path=$this->root.'/storage/uploads/'.$stored;if(!is_file($path)){http_response_code(404);exit;}
        header('Content-Type: '.(string)$version['mime_type']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode((string)$version['original_name']));header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }

    public function streamInlineImage(array $version): never
    {
        $mime=mb_strtolower((string)$version['mime_type']);if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp'],true)){http_response_code(404);exit;}$stored=(string)$version['stored_name'];if(!preg_match('/^[a-f0-9]{48}$/',$stored)){http_response_code(404);exit;}$path=$this->root.'/storage/uploads/'.$stored;if(!is_file($path)){http_response_code(404);exit;}header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename*=UTF-8\'\''.rawurlencode((string)$version['original_name']));header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; img-src 'self'");readfile($path);exit;
    }

    private function findLogical(int $attachmentId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}attachments` WHERE id=? AND deleted_at IS NULL");$stmt->execute([$attachmentId]);return $stmt->fetch()?:null;
    }
}
