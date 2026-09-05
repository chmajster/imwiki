<?php
declare(strict_types=1);

namespace ImWiki\Security;

use InvalidArgumentException;

final class SsrfGuard
{
    /** @return array{scheme:string,host:string,port:int,path:string,ip:string} */
    public function validate(string $url): array
    {
        if(strlen($url)>1000) throw new InvalidArgumentException('URL is too long.');
        $parts=parse_url($url);if(!is_array($parts))throw new InvalidArgumentException('Invalid URL.');
        $scheme=strtolower((string)($parts['scheme']??''));if(!in_array($scheme,['http','https'],true))throw new InvalidArgumentException('Only HTTP/HTTPS are allowed.');
        if(isset($parts['user'])||isset($parts['pass']))throw new InvalidArgumentException('Credentials in URL are not allowed.');
        $host=strtolower(rtrim((string)($parts['host']??''),'.'));if($host===''||$host==='localhost'||str_ends_with($host,'.localhost'))throw new InvalidArgumentException('Local destination is blocked.');
        $port=(int)($parts['port']??($scheme==='https'?443:80));if($port<1||$port>65535)throw new InvalidArgumentException('Invalid port.');
        $records=[];
        if(filter_var($host,FILTER_VALIDATE_IP)){$records[]=$host;}else{
            if(function_exists('dns_get_record')){
                foreach((array)@dns_get_record($host,DNS_A|DNS_AAAA) as $r){if(isset($r['ip']))$records[]=(string)$r['ip'];if(isset($r['ipv6']))$records[]=(string)$r['ipv6'];}
            }
            if(!$records){$resolved=@gethostbyname($host);if($resolved!==$host)$records[]=$resolved;}
        }
        $records=array_values(array_unique($records));if(!$records)throw new InvalidArgumentException('Host cannot be resolved.');
        foreach($records as $ip)if(!$this->isPublicIp($ip))throw new InvalidArgumentException('Private or local destination is blocked.');
        $path=(string)($parts['path']??'/');if($path==='')$path='/';if(isset($parts['query']))$path.='?'.$parts['query'];
        return ['scheme'=>$scheme,'host'=>$host,'port'=>$port,'path'=>$path,'ip'=>$records[0]];
    }

    public function isPublicIp(string $ip): bool
    {
        if(!filter_var($ip,FILTER_VALIDATE_IP))return false;
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;
        if($ip==='169.254.169.254')return false;
        $packed=@inet_pton($ip);if($packed===false)return false;
        if(strlen($packed)===16){
            $hex=bin2hex($packed);
            if(str_starts_with($hex,'fc')||str_starts_with($hex,'fd'))return false; // fc00::/7
            $first=hexdec(substr($hex,0,4));if(($first&0xffc0)===0xfe80)return false; // fe80::/10
        }
        return true;
    }
}
