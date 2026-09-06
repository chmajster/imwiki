<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Url
{
    public static function basePath(): string
    {
        $config=Config::all();
        if(isset($config['app'])&&is_array($config['app'])&&array_key_exists('base_path',$config['app'])){
            return self::normalizeBasePath((string)$config['app']['base_path']);
        }
        $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/index.php'));
        $dir=rtrim(dirname($script),'/');
        return $dir==='.'||$dir==='/'?'':self::normalizeBasePath($dir);
    }

    public static function to(string $path = '/'): string
    {
        $base=self::basePath();
        $path='/'.ltrim($path,'/');
        if($path==='//')$path='/';
        return rtrim($base,'/').$path;
    }

    public static function currentAppUrl(): string
    {
        $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(($_SERVER['SERVER_PORT']??'')==='443');
        $scheme=$https?'https':'http';
        $host=(string)($_SERVER['SERVER_NAME']??'localhost');
        $port=(string)($_SERVER['SERVER_PORT']??'');
        if($port!==''&&!in_array($port,['80','443'],true))$host.=':'.$port;
        return $scheme.'://'.$host.self::basePath();
    }

    private static function normalizeBasePath(string $base):string
    {
        $base=str_replace('\\','/',trim($base));
        if($base===''||$base==='/')return'';
        $base='/'.trim($base,'/');
        return preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#',$base)?rtrim($base,'/'):'';
    }
}
