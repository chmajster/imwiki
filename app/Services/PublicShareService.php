<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;
use RuntimeException;

final class PublicShareService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function enabled():bool
    {
        $stmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='sharing.public_enabled' LIMIT 1");
        $stmt->execute();return (string)($stmt->fetchColumn()?:'0')==='1';
    }

    public function setEnabled(bool $enabled):void
    {
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES ('sharing.public_enabled',?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)");
        $stmt->execute([$enabled?'1':'0']);
    }

    public function create(int $pageId,int $userId,?string $expiresAt,?string $password):array
    {
        if(!$this->enabled())throw new RuntimeException('Public sharing jest wyłączony przez administratora.');
        $raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $hash=hash('sha256',$raw);$prefix=substr($raw,0,10);
        $expiry=$expiresAt!==null&&$expiresAt!==''?$expiresAt.' 23:59:59':null;
        $passwordHash=$password!==null&&$password!==''?password_hash($password,PASSWORD_DEFAULT):null;
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}public_shares` (page_id,created_by,token_hash,token_prefix,password_hash,expires_at,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
        $stmt->execute([$pageId,$userId,$hash,$prefix,$passwordHash,$expiry]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'token'=>$raw,'expires_at'=>$expiry,'password_protected'=>$passwordHash!==null];
    }

    public function listForPage(int $pageId):array
    {
        $stmt=$this->pdo->prepare("SELECT ps.id,ps.token_prefix,ps.expires_at,ps.last_used_at,ps.revoked_at,ps.created_at,ps.password_hash IS NOT NULL password_protected,CONCAT(u.first_name,' ',u.last_name) creator_name FROM `{$this->prefix}public_shares` ps JOIN `{$this->prefix}users` u ON u.id=ps.created_by WHERE ps.page_id=? ORDER BY ps.created_at DESC LIMIT 100");
        $stmt->execute([$pageId]);return $stmt->fetchAll();
    }

    public function revoke(int $shareId,int $pageId):bool
    {
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}public_shares` SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND page_id=? AND revoked_at IS NULL");
        $stmt->execute([$shareId,$pageId]);return $stmt->rowCount()>0;
    }

    public function resolve(string $token):?array
    {
        if(!$this->enabled()||$token==='')return null;
        $stmt=$this->pdo->prepare("SELECT ps.*,p.title,p.content,p.status,p.updated_at,s.name space_name FROM `{$this->prefix}public_shares` ps JOIN `{$this->prefix}pages` p ON p.id=ps.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE ps.token_hash=? AND ps.revoked_at IS NULL AND (ps.expires_at IS NULL OR ps.expires_at>=UTC_TIMESTAMP()) AND p.deleted_at IS NULL AND p.status<>'archived' LIMIT 1");
        $stmt->execute([hash('sha256',$token)]);$share=$stmt->fetch();return $share?:null;
    }

    public function verifyPassword(array $share,string $password):bool
    {
        $hash=(string)($share['password_hash']??'');return $hash===''||password_verify($password,$hash);
    }

    public function touch(int $id):void
    {
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}public_shares` SET last_used_at=UTC_TIMESTAMP() WHERE id=?");$stmt->execute([$id]);
    }
}
