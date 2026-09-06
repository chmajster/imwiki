<?php
declare(strict_types=1);

use ImWiki\Security\SecurityHeaders;
use ImWiki\Support\Autoloader;
use ImWiki\Support\Config;
use ImWiki\Support\Stage3FrontController;
use ImWiki\Support\Url;

require_once __DIR__ . '/app/Support/Autoloader.php';
Autoloader::register(__DIR__);
Config::load(__DIR__ . '/config/config.php');

define('IMWIKI_VERSION', '0.3.0');
if (!defined('IMWIKI_REQUEST_ID')) {
    define('IMWIKI_REQUEST_ID', bin2hex(random_bytes(8)));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('imwiki_session');
    $basePath = Url::basePath();
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => ($basePath === '' ? '/' : rtrim($basePath,'/') . '/'),
    ]);
    if (!@session_start()) {
        http_response_code(500);
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>imWiki</title><main><h1>Nie można uruchomić bezpiecznej sesji.</h1><p>Administrator powinien sprawdzić konfigurację session storage PHP.</p></main></html>';
        exit;
    }
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
SecurityHeaders::send([
    'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'hsts' => false,
    'csp_report_only' => false,
]);

// Stage 3 augments selected enterprise/auth/health routes while the proven Stage 2
// front controller remains responsible for the rest of the application.
if (Stage3FrontController::shouldHandle()) {
    if (Stage3FrontController::handle(__DIR__)) {
        exit;
    }
}
