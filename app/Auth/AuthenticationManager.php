<?php
declare(strict_types=1);

namespace ImWiki\Auth;

use ImWiki\Security\Crypto;
use ImWiki\Services\AuthService;
use PDO;
use RuntimeException;

final class AuthenticationManager
{
    private LocalAuthenticationProvider $local;
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,AuthService $auth,private readonly Crypto $crypto){$this->local=new LocalAuthenticationProvider($auth);}

    public function passwordProviders():array
    {
        $out=[['key'=>'local','name'=>'Local account','type'=>'local']];$stmt=$this->pdo->query("SELECT provider_key,display_name,provider_type FROM `{$this->prefix}auth_providers` WHERE enabled=1 AND provider_type='ldap' ORDER BY display_name");foreach($stmt->fetchAll() as $r)$out[]=['key'=>$r['provider_key'],'name'=>$r['display_name'],'type'=>$r['provider_type']];return$out;
    }

    public function authenticate(string $providerKey,string $login,string $password):?array
    {
        if($providerKey===''||$providerKey==='local')return$this->local->authenticate(['login'=>$login,'password'=>$password]);$row=$this->provider($providerKey);if($row['provider_type']!=='ldap')throw new RuntimeException('Selected provider does not accept a password on this form.');$cfg=json_decode((string)$row['config_json'],true)?:[];$secret=$row['secret_encrypted']?$this->crypto->decrypt((string)$row['secret_encrypted']):null;$provider=new LdapAuthenticationProvider((string)$row['provider_key'],(string)$row['display_name'],$cfg,$secret,(bool)$row['enabled']);return$provider->authenticate(['login'=>$login,'password'=>$password]);
    }

    public function saveLdap(string $key,string $name,array $config,?string $bindPassword,bool $enabled,bool $autoProvision,string $defaultRole):void
    {
        if(!preg_match('/^[a-z0-9_-]{2,80}$/',$key))throw new RuntimeException('Invalid provider key.');$host=trim((string)($config['host']??''));$base=trim((string)($config['base_dn']??''));if($host===''||$base==='')throw new RuntimeException('LDAP host and base DN are required.');$secret=$bindPassword!==null&&$bindPassword!==''?$this->crypto->encrypt($bindPassword):null;$sql="INSERT INTO `{$this->prefix}auth_providers` (provider_key,provider_type,display_name,enabled,config_json,secret_encrypted,auto_provision,default_role,created_at,updated_at) VALUES (?,'ldap',?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),enabled=VALUES(enabled),config_json=VALUES(config_json),secret_encrypted=COALESCE(VALUES(secret_encrypted),secret_encrypted),auto_provision=VALUES(auto_provision),default_role=VALUES(default_role),updated_at=UTC_TIMESTAMP()";$this->pdo->prepare($sql)->execute([$key,mb_substr(trim($name),0,150),$enabled?1:0,json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$secret,$autoProvision?1:0,$defaultRole?:null]);
    }

    public function providers():array
    {
        $rows=$this->pdo->query("SELECT provider_key,provider_type,display_name,enabled,config_json,auto_provision,default_role,created_at,updated_at FROM `{$this->prefix}auth_providers` ORDER BY provider_type,display_name")->fetchAll();foreach($rows as &$r){$r['config']=json_decode((string)$r['config_json'],true)?:[];unset($r['config_json']);}return$rows;
    }

    public function setEnabled(string $key,bool $enabled):void
    {
        if($key==='local'&&!$enabled)throw new RuntimeException('Local authentication is the emergency access path and cannot be disabled.');$this->pdo->prepare("UPDATE `{$this->prefix}auth_providers` SET enabled=?,updated_at=UTC_TIMESTAMP() WHERE provider_key=?")->execute([$enabled?1:0,$key]);
    }

    private function provider(string $key):array{$s=$this->pdo->prepare("SELECT * FROM `{$this->prefix}auth_providers` WHERE provider_key=? AND enabled=1 LIMIT 1");$s->execute([$key]);return$s->fetch()?:throw new RuntimeException('Authentication provider is unavailable.');}
}
