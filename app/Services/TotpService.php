<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Crypto;
use PDO;
use PDOException;
use RuntimeException;

final class TotpService
{
    private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Crypto $crypto){}

    public function enabled(int $userId):bool
    {
        try{$stmt=$this->pdo->prepare("SELECT confirmed_at IS NOT NULL FROM `{$this->prefix}user_totp` WHERE user_id=?");$stmt->execute([$userId]);return (bool)$stmt->fetchColumn();}catch(PDOException){return false;}
    }

    public function begin(int $userId):string
    {
        $secret=$this->base32Encode(random_bytes(20));$encrypted=$this->crypto->encrypt($secret);
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}user_totp` (user_id,secret_encrypted,enabled_at,confirmed_at,updated_at) VALUES (?,?,NULL,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_encrypted=VALUES(secret_encrypted),enabled_at=NULL,confirmed_at=NULL,updated_at=VALUES(updated_at)");
        $stmt->execute([$userId,$encrypted]);return $secret;
    }

    public function pendingSecret(int $userId):?string
    {
        $stmt=$this->pdo->prepare("SELECT secret_encrypted FROM `{$this->prefix}user_totp` WHERE user_id=? AND confirmed_at IS NULL");$stmt->execute([$userId]);$v=$stmt->fetchColumn();return $v?$this->crypto->decrypt((string)$v):null;
    }

    public function provisioningUri(string $issuer,string $account,string $secret):string
    {
        $label=rawurlencode($issuer.':'.$account);return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function confirm(int $userId,string $code):array
    {
        $secret=$this->pendingSecret($userId);if(!$secret||!$this->verifySecret($secret,$code))throw new RuntimeException('Nieprawidłowy kod TOTP.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("UPDATE `{$this->prefix}user_totp` SET enabled_at=UTC_TIMESTAMP(),confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE user_id=?");$stmt->execute([$userId]);
            $this->pdo->prepare("DELETE FROM `{$this->prefix}recovery_codes` WHERE user_id=?")->execute([$userId]);
            $codes=[];$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}recovery_codes` (user_id,code_hash,created_at) VALUES (?,?,UTC_TIMESTAMP())");
            for($i=0;$i<10;$i++){ $codeRaw=strtoupper(bin2hex(random_bytes(5)));$display=substr($codeRaw,0,5).'-'.substr($codeRaw,5,5);$stmt->execute([$userId,hash('sha256',$this->normalizeRecovery($display))]);$codes[]=$display; }
            $this->pdo->commit();return $codes;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function verifyUser(int $userId,string $code):bool
    {
        $stmt=$this->pdo->prepare("SELECT secret_encrypted FROM `{$this->prefix}user_totp` WHERE user_id=? AND confirmed_at IS NOT NULL");$stmt->execute([$userId]);$encrypted=$stmt->fetchColumn();
        if(!$encrypted)return false;return $this->verifySecret($this->crypto->decrypt((string)$encrypted),$code);
    }

    public function consumeRecoveryCode(int $userId,string $code):bool
    {
        $hash=hash('sha256',$this->normalizeRecovery($code));$stmt=$this->pdo->prepare("UPDATE `{$this->prefix}recovery_codes` SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND code_hash=? AND used_at IS NULL");$stmt->execute([$userId,$hash]);return $stmt->rowCount()===1;
    }

    public function disable(int $userId):void
    {
        $this->pdo->beginTransaction();try{$this->pdo->prepare("DELETE FROM `{$this->prefix}recovery_codes` WHERE user_id=?")->execute([$userId]);$this->pdo->prepare("DELETE FROM `{$this->prefix}user_totp` WHERE user_id=?")->execute([$userId]);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function remainingRecoveryCodes(int $userId):int
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}recovery_codes` WHERE user_id=? AND used_at IS NULL");$stmt->execute([$userId]);return (int)$stmt->fetchColumn();
    }

    private function verifySecret(string $secret,string $code):bool
    {
        $code=preg_replace('/\s+/','',$code)??'';if(!preg_match('/^\d{6}$/',$code))return false;$counter=(int)floor(time()/30);
        for($offset=-1;$offset<=1;$offset++){if(hash_equals($this->hotp($secret,$counter+$offset),$code))return true;}return false;
    }

    private function hotp(string $base32,int $counter):string
    {
        $key=$this->base32Decode($base32);$high=intdiv($counter,0x100000000);$low=$counter%0x100000000;$binary=pack('N2',$high,$low);$hash=hash_hmac('sha1',$binary,$key,true);$offset=ord($hash[19])&0x0f;$value=((ord($hash[$offset])&0x7f)<<24)|((ord($hash[$offset+1])&0xff)<<16)|((ord($hash[$offset+2])&0xff)<<8)|(ord($hash[$offset+3])&0xff);return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
    }

    private function base32Encode(string $data):string
    {
        $bits='';foreach(str_split($data) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $chunk){$out.=self::ALPHABET[bindec(str_pad($chunk,5,'0'))];}return $out;
    }

    private function base32Decode(string $text):string
    {
        $text=strtoupper(preg_replace('/[^A-Z2-7]/','',$text)??'');$bits='';foreach(str_split($text) as $c){$pos=strpos(self::ALPHABET,$c);if($pos===false)throw new RuntimeException('Invalid base32.');$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);} $out='';foreach(str_split($bits,8) as $chunk){if(strlen($chunk)===8)$out.=chr(bindec($chunk));}return $out;
    }

    private function normalizeRecovery(string $code):string{return strtoupper(preg_replace('/[^A-F0-9]/i','',$code)??'');}
}
