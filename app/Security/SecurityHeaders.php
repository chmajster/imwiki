<?php
declare(strict_types=1);

namespace ImWiki\Security;

final class SecurityHeaders
{
    public static function send(array $options=[]):void
    {
        if(headers_sent())return;
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: '.self::token((string)($options['referrer_policy']??'strict-origin-when-cross-origin'),'strict-origin-when-cross-origin'));
        header('Permissions-Policy: '.(string)($options['permissions_policy']??'camera=(), microphone=(), geolocation=()'));
        $csp=(string)($options['csp']??"default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        header(((bool)($options['csp_report_only']??false)?'Content-Security-Policy-Report-Only':'Content-Security-Policy').': '.$csp);
        $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||mb_strtolower((string)($_SERVER['HTTP_X_IMWIKI_FORWARDED_PROTO']??''))==='https';
        if($https&&!empty($options['hsts']))header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    private static function token(string $value,string $fallback):string
    {
        return preg_match('/^[a-z-]{2,60}$/',$value)?$value:$fallback;
    }
}
