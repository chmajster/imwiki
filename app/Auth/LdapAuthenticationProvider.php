<?php
declare(strict_types=1);

namespace ImWiki\Auth;

use RuntimeException;

final class LdapAuthenticationProvider implements AuthenticationProviderInterface
{
    public function __construct(private readonly string $providerKey,private readonly string $name,private readonly array $config,private readonly ?string $bindPassword,private readonly bool $isEnabled=true){}
    public function key():string{return$this->providerKey;}
    public function type():string{return'ldap';}
    public function displayName():string{return$this->name;}
    public function enabled():bool{return$this->isEnabled;}
    public function mode():string{return'password';}

    public function authenticate(array $credentials):?array
    {
        if(!$this->isEnabled)return null;if(!extension_loaded('ldap'))throw new RuntimeException('LDAP extension is not available on this server.');$login=trim((string)($credentials['login']??''));$password=(string)($credentials['password']??'');if($login===''||$password===''||mb_strlen($login)>190)return null;
        $host=(string)($this->config['host']??'');$port=(int)($this->config['port']??636);$baseDn=(string)($this->config['base_dn']??'');if($host===''||$baseDn==='')throw new RuntimeException('LDAP provider is incomplete.');if(!preg_match('/^[A-Za-z0-9._:-]+$/',$host))throw new RuntimeException('Invalid LDAP host.');
        $scheme=($this->config['tls_mode']??'ldaps')==='ldaps'?'ldaps':'ldap';$ldap=@ldap_connect($scheme.'://'.$host.':'.$port);if($ldap===false)throw new RuntimeException('Cannot initialize LDAP connection.');ldap_set_option($ldap,LDAP_OPT_PROTOCOL_VERSION,3);ldap_set_option($ldap,LDAP_OPT_REFERRALS,0);ldap_set_option($ldap,LDAP_OPT_NETWORK_TIMEOUT,max(2,min(15,(int)($this->config['timeout']??5))));
        if(($this->config['tls_mode']??'ldaps')==='starttls'&&!@ldap_start_tls($ldap))throw new RuntimeException('LDAP StartTLS failed.');$bindDn=(string)($this->config['bind_dn']??'');if($bindDn!==''&&!@ldap_bind($ldap,$bindDn,(string)$this->bindPassword))return null;
        $escaped=function_exists('ldap_escape')?ldap_escape($login,'',LDAP_ESCAPE_FILTER):preg_replace('/[^A-Za-z0-9_.@-]/','',$login);$template=(string)($this->config['user_filter']??'(uid={username})');if(!str_contains($template,'{username}'))throw new RuntimeException('LDAP user filter must contain {username}.');$filter=str_replace('{username}',$escaped,$template);$attrs=array_values(array_unique(array_filter([(string)($this->config['id_attr']??'entryuuid'),(string)($this->config['username_attr']??'uid'),(string)($this->config['email_attr']??'mail'),(string)($this->config['first_name_attr']??'givenname'),(string)($this->config['last_name_attr']??'sn'),(string)($this->config['groups_attr']??'memberof')])));
        $search=@ldap_search($ldap,$baseDn,$filter,$attrs,0,2);if($search===false)return null;$entries=ldap_get_entries($ldap,$search);if(($entries['count']??0)!==1)return null;$entry=$entries[0];$dn=(string)($entry['dn']??'');if($dn===''||!@ldap_bind($ldap,$dn,$password))return null;
        $one=static function(array $entry,string $attr):string{$key=mb_strtolower($attr);return isset($entry[$key][0])?(string)$entry[$key][0]:'';};$groups=[];$gk=mb_strtolower((string)($this->config['groups_attr']??'memberof'));if(isset($entry[$gk])&&is_array($entry[$gk]))for($i=0;$i<(int)($entry[$gk]['count']??0);$i++)$groups[]=(string)$entry[$gk][$i];
        $external=$one($entry,(string)($this->config['id_attr']??'entryuuid'));if($external==='')$external=hash('sha256',$dn);return['external_id'=>$external,'username'=>$one($entry,(string)($this->config['username_attr']??'uid'))?:$login,'email'=>$one($entry,(string)($this->config['email_attr']??'mail')),'first_name'=>$one($entry,(string)($this->config['first_name_attr']??'givenname')),'last_name'=>$one($entry,(string)($this->config['last_name_attr']??'sn')),'groups'=>$groups,'provider'=>$this->providerKey];
    }
}
