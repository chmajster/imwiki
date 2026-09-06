<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class SecretMasker
{
    private const SENSITIVE=['password','passwd','token','secret','authorization','cookie','session','api_key','apikey','client_secret','smtp_password','db_password','recovery_code','totp'];

    public static function mask(mixed $value):mixed
    {
        if(is_array($value)){
            $out=[];foreach($value as $k=>$v){$name=mb_strtolower((string)$k);$sensitive=false;foreach(self::SENSITIVE as $needle){if(str_contains($name,$needle)){$sensitive=true;break;}}$out[$k]=$sensitive?'[REDACTED]':self::mask($v);}return$out;
        }
        if(is_object($value))return self::mask((array)$value);
        if(!is_string($value))return$value;
        $masked=preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i','$1[REDACTED]',$value)??$value;
        $masked=preg_replace('/\b(imw_[A-Za-z0-9_-]{8,})\b/','imw_[REDACTED]',$masked)??$masked;
        $masked=preg_replace('/((?:password|token|secret|client_secret)\s*[=:]\s*)[^\s,;]+/i','$1[REDACTED]',$masked)??$masked;
        return$masked;
    }
}
