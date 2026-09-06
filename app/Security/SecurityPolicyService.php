<?php
declare(strict_types=1);

namespace ImWiki\Security;

use ImWiki\Exceptions\ValidationException;
use PDO;

final class SecurityPolicyService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}
    public function all():array{$keys=['security.session_lifetime','security.idle_timeout','security.max_login_attempts','security.lockout_seconds','security.min_password_length','security.password_reset_expiry','security.require_2fa','security.allowed_auth_methods','security.trusted_device_days','security.trusted_proxies','security.hsts','security.csp_report_only','system.read_only'];$out=[];$stmt=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");foreach($keys as $key){$stmt->execute([$key]);$out[$key]=(string)($stmt->fetchColumn()?:'');}return$out;}
    public function save(array $input):void{$rules=['security.session_lifetime'=>[300,2592000],'security.idle_timeout'=>[60,604800],'security.max_login_attempts'=>[3,100],'security.lockout_seconds'=>[30,86400],'security.min_password_length'=>[8,128],'security.password_reset_expiry'=>[300,86400],'security.trusted_device_days'=>[1,365]];foreach($rules as $key=>[$min,$max])if(array_key_exists($key,$input)){$v=(int)$input[$key];if($v<$min||$v>$max)throw new ValidationException('Invalid security policy: '.$key);$this->set($key,(string)$v);}foreach(['security.require_2fa','security.hsts','security.csp_report_only','system.read_only'] as $key)if(array_key_exists($key,$input))$this->set($key,!empty($input[$key])?'1':'0');if(array_key_exists('security.allowed_auth_methods',$input)){$methods=array_values(array_intersect(['local','ldap','oidc'],array_map('strval',(array)$input['security.allowed_auth_methods'])));if(!in_array('local',$methods,true))$methods[]='local';$this->set('security.allowed_auth_methods',json_encode(array_values(array_unique($methods)),JSON_THROW_ON_ERROR));}if(array_key_exists('security.trusted_proxies',$input)){$raw=is_array($input['security.trusted_proxies'])?$input['security.trusted_proxies']:preg_split('/[\r\n,]+/',(string)$input['security.trusted_proxies']);$cidrs=[];foreach($raw?:[] as $cidr){$cidr=trim((string)$cidr);if($cidr==='')continue;if(!Cidr::valid($cidr))throw new ValidationException('Invalid trusted proxy CIDR: '.$cidr);$cidrs[]=$cidr;}$this->set('security.trusted_proxies',json_encode(array_values(array_unique($cidrs)),JSON_THROW_ON_ERROR));}}
    public function validatePassword(string $password):void{$min=max(8,(int)$this->get('security.min_password_length','12'));if(mb_strlen($password)<$min)throw new ValidationException('Password must contain at least '.$min.' characters.');if(mb_strlen($password)>1024)throw new ValidationException('Password is too long.');}
    public function allowedAuth(string $type):bool{$methods=json_decode($this->get('security.allowed_auth_methods','["local"]'),true);return is_array($methods)&&in_array($type,$methods,true);}
    public function headers():array{return['hsts'=>$this->get('security.hsts','0')==='1','csp_report_only'=>$this->get('security.csp_report_only','0')==='1'];}
    public function get(string $key,string $default=''):string{$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
    public function set(string $key,string $value):void{$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()")->execute([$key,$value]);}
}
