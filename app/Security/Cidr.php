<?php
declare(strict_types=1);

namespace ImWiki\Security;

final class Cidr
{
    public static function contains(string $cidr,string $ip):bool
    {
        $cidr=trim($cidr);$ip=trim($ip);if($cidr===''||$ip==='')return false;
        [$network,$bits]=array_pad(explode('/',$cidr,2),2,null);$net=@inet_pton($network);$addr=@inet_pton($ip);if($net===false||$addr===false||strlen($net)!==strlen($addr))return false;
        $max=strlen($net)*8;$prefix=$bits===null?$max:(int)$bits;if($prefix<0||$prefix>$max)return false;
        $bytes=intdiv($prefix,8);$remain=$prefix%8;if($bytes>0&&substr($net,0,$bytes)!==substr($addr,0,$bytes))return false;if($remain===0)return true;
        $mask=(0xff << (8-$remain)) & 0xff;return (ord($net[$bytes])&$mask)===(ord($addr[$bytes])&$mask);
    }

    public static function valid(string $cidr):bool
    {
        [$ip,$bits]=array_pad(explode('/',trim($cidr),2),2,null);$packed=@inet_pton($ip);if($packed===false)return false;$max=strlen($packed)*8;if($bits===null)return true;return ctype_digit($bits)&&(int)$bits>=0&&(int)$bits<=$max;
    }
}
