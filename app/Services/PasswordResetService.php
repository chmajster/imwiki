<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\UserRepository;
use PDO;
use RuntimeException;

final class PasswordResetService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly UserRepository $users,private readonly SessionService $sessions){}
    public function issue(string $identifier):?array
    {
        $user=$this->users->findByLogin(trim($identifier));if(!$user||$user['status']!=='active')return null;$uid=(int)$user['id'];$raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$hash=hash('sha256',$raw);
        $this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE `{$this->prefix}password_reset_tokens` SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL")->execute([$uid]);$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}password_reset_tokens` (user_id,token_hash,expires_at,created_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE),UTC_TIMESTAMP())");$stmt->execute([$uid,$hash]);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['token'=>$raw,'email'=>(string)$user['email'],'user_id'=>$uid,'username'=>(string)$user['username']];
    }
    public function validate(string $token):?array
    {
        if($token==='')return null;$stmt=$this->pdo->prepare("SELECT pr.id token_id,pr.user_id,u.username,u.email FROM `{$this->prefix}password_reset_tokens` pr JOIN `{$this->prefix}users` u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>=UTC_TIMESTAMP() AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");$stmt->execute([hash('sha256',$token)]);return $stmt->fetch()?:null;
    }
    public function reset(string $token,string $password):int
    {
        if(mb_strlen($password)<10)throw new RuntimeException('Hasło musi mieć co najmniej 10 znaków.');$row=$this->validate($token);if(!$row)throw new RuntimeException('Token resetu jest nieważny lub wygasł.');$uid=(int)$row['user_id'];
        $this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE `{$this->prefix}users` SET password_hash=?,force_password_change=0,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);$this->pdo->prepare("UPDATE `{$this->prefix}password_reset_tokens` SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL")->execute([(int)$row['token_id']]);$this->pdo->prepare("UPDATE `{$this->prefix}api_tokens` SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL")->execute([$uid]);$this->sessions->revokeAllForUser($uid);$this->pdo->commit();return $uid;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
