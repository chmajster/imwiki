<?php
declare(strict_types=1);

namespace ImWiki\Security;

use PDO;

final class IpAccessPolicy
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function trustedProxies():array
    {
        $s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='security.trusted_proxies' LIMIT 1");$s->execute();$v=json_decode((string)($s->fetchColumn()?:'[]'),true);return is_array($v)?array_values(array_filter(array_map('strval',$v),static fn(string $x):bool=>Cidr::valid($x))):[];
    }

    public function clientIp(array $server):string
    {
        $remote=(string)($server['REMOTE_ADDR']??'0.0.0.0');$trusted=false;foreach($this->trustedProxies() as $cidr){if(Cidr::contains($cidr,$remote)){$trusted=true;break;}}if(!$trusted)return$remote;
        $xff=(string)($server['HTTP_X_FORWARDED_FOR']??'');if($xff==='')return$remote;$parts=array_map('trim',explode(',',$xff));foreach($parts as $part){if(filter_var($part,FILTER_VALIDATE_IP))return$part;}return$remote;
    }

    public function forwardedProto(array $server):?string
    {
        $remote=(string)($server['REMOTE_ADDR']??'');$trusted=false;foreach($this->trustedProxies() as $cidr){if(Cidr::contains($cidr,$remote)){$trusted=true;break;}}if(!$trusted)return null;$proto=mb_strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO']??'')));return in_array($proto,['http','https'],true)?$proto:null;
    }

    public function allowed(string $scope,string $ip):bool
    {
        if(!in_array($scope,['global','admin'],true)||!filter_var($ip,FILTER_VALIDATE_IP))return false;
        $stmt=$this->pdo->prepare("SELECT action,cidr FROM `{$this->prefix}ip_access_rules` WHERE enabled=1 AND scope=? ORDER BY CASE action WHEN 'deny' THEN 0 ELSE 1 END,id");$stmt->execute([$scope]);$rules=$stmt->fetchAll();if(!$rules)return true;
        $hasAllow=false;$allow=false;foreach($rules as $rule){if($rule['action']==='allow')$hasAllow=true;if(Cidr::contains((string)$rule['cidr'],$ip)){if($rule['action']==='deny')return false;$allow=true;}}
        return $hasAllow?$allow:true;
    }

    public function saveRule(string $scope,string $action,string $cidr,string $description,int $actorId):int
    {
        if(!in_array($scope,['global','admin'],true)||!in_array($action,['allow','deny'],true)||!Cidr::valid($cidr))throw new \InvalidArgumentException('Invalid IP rule.');$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}ip_access_rules` (scope,action,cidr,description,enabled,created_by,created_at) VALUES (?,?,?,?,1,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE description=VALUES(description),enabled=1");$stmt->execute([$scope,$action,trim($cidr),mb_substr(trim($description),0,255),$actorId]);return(int)$this->pdo->lastInsertId();
    }
}
