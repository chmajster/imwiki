<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\UserRepository;
use PDO;

final class ApiTokenService
{
    public const SCOPES=['pages:read','pages:write','spaces:read','attachments:read','attachments:write'];

    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly UserRepository $users){}

    public function create(int $userId,string $name,array $scopes,?string $expiresDate):array
    {
        $name=trim($name);if($name===''||mb_strlen($name)>120)throw new \InvalidArgumentException('Nazwa tokena jest wymagana.');
        $scopes=array_values(array_unique(array_intersect(self::SCOPES,$scopes)));if(!$scopes)throw new \InvalidArgumentException('Wybierz co najmniej jeden scope.');
        $expiresAt=null;if($expiresDate!==null&&$expiresDate!==''){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expiresDate))throw new \InvalidArgumentException('Nieprawidłowa data wygaśnięcia.');$expiresAt=$expiresDate.' 23:59:59';if(strtotime($expiresAt)<=time())throw new \InvalidArgumentException('Data wygaśnięcia musi być w przyszłości.');}
        $raw='imw_'.self::base64url(random_bytes(32));$hash=hash('sha256',$raw);$prefix=substr($raw,0,16);
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}api_tokens` (user_id,name,token_prefix,token_hash,scopes_json,expires_at,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
        $stmt->execute([$userId,$name,$prefix,$hash,json_encode($scopes,JSON_UNESCAPED_SLASHES),$expiresAt]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'token'=>$raw,'name'=>$name,'scopes'=>$scopes,'expires_at'=>$expiresAt];
    }

    public function listForUser(int $userId):array
    {
        $stmt=$this->pdo->prepare("SELECT id,name,token_prefix,scopes_json,expires_at,last_used_at,created_at,revoked_at FROM `{$this->prefix}api_tokens` WHERE user_id=? ORDER BY created_at DESC LIMIT 100");$stmt->execute([$userId]);$rows=$stmt->fetchAll();
        foreach($rows as &$row)$row['scopes']=json_decode((string)$row['scopes_json'],true)?:[];unset($row);return $rows;
    }

    public function revoke(int $userId,int $tokenId):void
    {
        $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}api_tokens` SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=? AND user_id=?");$stmt->execute([$tokenId,$userId]);
    }

    public function authenticate(?string $authorization):?array
    {
        if(!$authorization||!preg_match('/^Bearer\s+(imw_[A-Za-z0-9_-]{30,})$/i',trim($authorization),$m))return null;
        $hash=hash('sha256',$m[1]);$stmt=$this->pdo->prepare("SELECT t.*,u.status,u.deleted_at FROM `{$this->prefix}api_tokens` t JOIN `{$this->prefix}users` u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at>UTC_TIMESTAMP()) LIMIT 1");$stmt->execute([$hash]);$row=$stmt->fetch();
        if(!$row||$row['status']!=='active'||$row['deleted_at']!==null)return null;
        $this->pdo->prepare("UPDATE `{$this->prefix}api_tokens` SET last_used_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        return ['token_id'=>(int)$row['id'],'user_id'=>(int)$row['user_id'],'scopes'=>json_decode((string)$row['scopes_json'],true)?:[]];
    }

    public static function hasScope(array $auth,string $scope):bool{return in_array($scope,$auth['scopes']??[],true);}
    private static function base64url(string $bytes):string{return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
}
