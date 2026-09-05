<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Url
{
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(dirname($script), '/');
        return $dir === '.' || $dir === '/' ? '' : $dir;
    }

    public static function to(string $path = '/'): string
    {
        $base = (string) Config::get('app.base_path', self::basePath());
        $path = '/' . ltrim($path, '/');
        if ($path === '//') {
            $path = '/';
        }
        return rtrim($base, '/') . $path;
    }

    public static function currentAppUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
        if ($port !== '' && !in_array($port, ['80', '443'], true)) {
            $host .= ':' . $port;
        }
        return $scheme . '://' . $host . self::basePath();
    }
}
