<?php
declare(strict_types=1);

use ImWiki\Support\Autoloader;
use ImWiki\Support\Config;
use ImWiki\Support\Url;

require_once __DIR__ . '/app/Support/Autoloader.php';
Autoloader::register(__DIR__);
Config::load(__DIR__ . '/config/config.php');

define('IMWIKI_VERSION', '0.2.0');
if (!defined('IMWIKI_REQUEST_ID')) {
    define('IMWIKI_REQUEST_ID', bin2hex(random_bytes(8)));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('imwiki_session');
    $basePath = Url::basePath();
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => ($basePath === '' ? '/' : rtrim($basePath,'/') . '/'),
    ]);
    session_start();
}

if (isset($_SESSION['user_id'])) {
    $now = time();
    $lastSeen = (int)($_SESSION['last_seen_at'] ?? $now);
    if (($now - $lastSeen) > 3600) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', $now - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen_at'] = $now;
    }
}

header('X-Request-ID: ' . IMWIKI_REQUEST_ID);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
