<?php
declare(strict_types=1);

namespace ImWiki\Auth;

use ImWiki\Http\SafeHttpClient;
use ImWiki\Security\Crypto;
use PDO;
use RuntimeException;

final class OidcService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Crypto $crypto,private readonly SafeHttpClient $http){}

    public function enabledProviders():array
    {
        $stmt=$this->pdo->query("SELECT provider_key,display_name FROM `{$this->prefix}auth_providers` WHERE provider_type='oidc' AND enabled=1 ORDER BY display_name");return$stmt->fetchAll()?:[];
    }

    public function authorizationUrl(string $providerKey,string $redirectUri):string
    {
        $provider=$this->provider($providerKey);$cfg=$provider['config'];$discovery=$this->discover($cfg);$state=$this->b64(random_bytes(32));$nonce=$this->b64(random_bytes(32));$verifier=$this->b64(random_bytes(48));$challenge=$this->b64(hash('sha256',$verifier,true));
        $_SESSION['oidc_states'][$state]=['provider'=>$providerKey,'nonce'=>$nonce,'verifier'=>$verifier,'redirect_uri'=>$redirectUri,'created_at'=>time()];$this->pruneStates();$scopes=trim((string)($cfg['scopes']??'openid profile email'));if(!preg_match('/\bopenid\b/',$scopes))$scopes='openid '.$scopes;
        $params=['response_type'=>'code','client_id'=>(string)($cfg['client_id']??''),'redirect_uri'=>$redirectUri,'scope'=>$scopes,'state'=>$state,'nonce'=>$nonce,'code_challenge'=>$challenge,'code_challenge_method'=>'S256'];if($params['client_id']==='')throw new RuntimeException('OIDC client ID is missing.');return(string)$discovery['authorization_endpoint'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }

    public function callback(string $providerKey,array $query,string $redirectUri):array
    {
        if(isset($query['error']))throw new RuntimeException('OIDC provider rejected authentication.');$state=(string)($query['state']??'');$code=(string)($query['code']??'');if($state===''||$code==='')throw new RuntimeException('Incomplete OIDC callback.');$stored=$_SESSION['oidc_states'][$state]??null;unset($_SESSION['oidc_states'][$state]);if(!is_array($stored)||!hash_equals((string)$stored['provider'],$providerKey)||time()-(int)$stored['created_at']>600||!hash_equals((string)$stored['redirect_uri'],$redirectUri))throw new RuntimeException('Invalid or expired OIDC state.');
        $provider=$this->provider($providerKey);$cfg=$provider['config'];$discovery=$this->discover($cfg);$form=['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri,'client_id'=>(string)$cfg['client_id'],'code_verifier'=>(string)$stored['verifier']];if(($provider['secret']??'')!=='')$form['client_secret']=$provider['secret'];$response=$this->http->request('POST',(string)$discovery['token_endpoint'],['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json'],http_build_query($form,'','&',PHP_QUERY_RFC3986));$tokens=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);if(!is_array($tokens)||empty($tokens['id_token']))throw new RuntimeException('OIDC token response did not include id_token.');$claims=$this->verifyIdToken((string)$tokens['id_token'],$discovery,(string)$cfg['client_id'],(string)$stored['nonce']);$claims['_provider']=$providerKey;return$claims;
    }

    public function saveProvider(string $key,string $name,array $config,?string $clientSecret,bool $enabled,bool $autoProvision,string $defaultRole):void
    {
        if(!preg_match('/^[a-z0-9_-]{2,80}$/',$key))throw new RuntimeException('Invalid provider key.');$issuer=rtrim(trim((string)($config['issuer']??'')),'/');if(!str_starts_with($issuer,'https://'))throw new RuntimeException('OIDC issuer must use HTTPS.');$config['issuer']=$issuer;$config['client_id']=trim((string)($config['client_id']??''));if($config['client_id']==='')throw new RuntimeException('OIDC client ID is required.');$secretEncrypted=$clientSecret!==null&&$clientSecret!==''?$this->crypto->encrypt($clientSecret):null;
        $sql="INSERT INTO `{$this->prefix}auth_providers` (provider_key,provider_type,display_name,enabled,config_json,secret_encrypted,auto_provision,default_role,created_at,updated_at) VALUES (?,'oidc',?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),enabled=VALUES(enabled),config_json=VALUES(config_json),secret_encrypted=COALESCE(VALUES(secret_encrypted),secret_encrypted),auto_provision=VALUES(auto_provision),default_role=VALUES(default_role),updated_at=UTC_TIMESTAMP()";$this->pdo->prepare($sql)->execute([$key,mb_substr(trim($name),0,150),$enabled?1:0,json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$secretEncrypted,$autoProvision?1:0,$defaultRole?:null]);
    }

    public function rawProvider(string $key):?array
    {
        $stmt=$this->pdo->prepare("SELECT provider_key,provider_type,display_name,enabled,config_json,auto_provision,default_role,created_at,updated_at FROM `{$this->prefix}auth_providers` WHERE provider_key=? LIMIT 1");$stmt->execute([$key]);$row=$stmt->fetch();if(!$row)return null;$row['config']=json_decode((string)$row['config_json'],true)?:[];unset($row['config_json']);return$row;
    }

    private function provider(string $key):array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}auth_providers` WHERE provider_key=? AND provider_type='oidc' AND enabled=1 LIMIT 1");$stmt->execute([$key]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('OIDC provider is unavailable.');$cfg=json_decode((string)$row['config_json'],true);if(!is_array($cfg))throw new RuntimeException('Invalid OIDC configuration.');return['row'=>$row,'config'=>$cfg,'secret'=>$row['secret_encrypted']?$this->crypto->decrypt((string)$row['secret_encrypted']):''];
    }

    private function discover(array $cfg):array
    {
        $issuer=rtrim((string)($cfg['issuer']??''),'/');if(!str_starts_with($issuer,'https://'))throw new RuntimeException('OIDC issuer must use HTTPS.');$doc=$this->http->getJson($issuer.'/.well-known/openid-configuration');if(rtrim((string)($doc['issuer']??''),'/')!==$issuer)throw new RuntimeException('OIDC issuer mismatch.');foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $key){if(empty($doc[$key])||!str_starts_with((string)$doc[$key],'https://'))throw new RuntimeException('OIDC discovery is incomplete.');}return$doc;
    }

    private function verifyIdToken(string $jwt,array $discovery,string $clientId,string $nonce):array
    {
        $parts=explode('.',$jwt);if(count($parts)!==3)throw new RuntimeException('Malformed ID token.');[$h64,$p64,$s64]=$parts;$header=json_decode($this->unb64($h64),true,512,JSON_THROW_ON_ERROR);$claims=json_decode($this->unb64($p64),true,512,JSON_THROW_ON_ERROR);if(!is_array($header)||!is_array($claims)||($header['alg']??'')!=='RS256'||empty($header['kid']))throw new RuntimeException('Unsupported ID token signature.');$jwks=$this->http->getJson((string)$discovery['jwks_uri']);$jwk=null;foreach(($jwks['keys']??[]) as $candidate){if(is_array($candidate)&&($candidate['kid']??null)===$header['kid']&&($candidate['kty']??null)==='RSA'){$jwk=$candidate;break;}}if(!$jwk)throw new RuntimeException('OIDC signing key not found.');$pem=$this->rsaJwkPem($jwk);$verified=openssl_verify($h64.'.'.$p64,$this->unb64($s64),$pem,OPENSSL_ALGO_SHA256);if($verified!==1)throw new RuntimeException('Invalid ID token signature.');$now=time();$issuer=rtrim((string)$discovery['issuer'],'/');if(rtrim((string)($claims['iss']??''),'/')!==$issuer)throw new RuntimeException('Invalid ID token issuer.');$aud=$claims['aud']??null;$audOk=is_array($aud)?in_array($clientId,$aud,true):hash_equals($clientId,(string)$aud);if(!$audOk)throw new RuntimeException('Invalid ID token audience.');if((int)($claims['exp']??0)<$now-30)throw new RuntimeException('Expired ID token.');if(isset($claims['nbf'])&&(int)$claims['nbf']>$now+30)throw new RuntimeException('ID token is not active yet.');if(!isset($claims['nonce'])||!hash_equals($nonce,(string)$claims['nonce']))throw new RuntimeException('Invalid OIDC nonce.');if(empty($claims['sub'])||mb_strlen((string)$claims['sub'])>255)throw new RuntimeException('OIDC subject is missing.');return$claims;
    }

    private function rsaJwkPem(array $jwk):string
    {
        if(empty($jwk['n'])||empty($jwk['e']))throw new RuntimeException('Invalid RSA JWK.');$n=$this->unb64((string)$jwk['n']);$e=$this->unb64((string)$jwk['e']);$rsa=$this->seq($this->integer($n).$this->integer($e));$alg=$this->seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");$spki=$this->seq($alg."\x03".$this->len(strlen($rsa)+1)."\x00".$rsa);return"-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
    }
    private function integer(string $bytes):string{$bytes=ltrim($bytes,"\x00");if($bytes===''||(ord($bytes[0])&0x80))$bytes="\x00".$bytes;return"\x02".$this->len(strlen($bytes)).$bytes;}
    private function seq(string $bytes):string{return"\x30".$this->len(strlen($bytes)).$bytes;}
    private function len(int $length):string{if($length<128)return chr($length);$hex=dechex($length);if(strlen($hex)%2)$hex='0'.$hex;$bin=hex2bin($hex);return chr(0x80|strlen($bin)).$bin;}
    private function b64(string $raw):string{return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
    private function unb64(string $value):string{$pad=(4-strlen($value)%4)%4;$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);if($decoded===false)throw new RuntimeException('Invalid base64url value.');return$decoded;}
    private function pruneStates():void{foreach(($_SESSION['oidc_states']??[]) as $k=>$v)if(!is_array($v)||time()-(int)($v['created_at']??0)>600)unset($_SESSION['oidc_states'][$k]);if(count($_SESSION['oidc_states']??[])>10)$_SESSION['oidc_states']=array_slice($_SESSION['oidc_states'],-10,null,true);}
}
