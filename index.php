<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/storage/installed.lock') || !is_file(__DIR__ . '/config/config.php')) {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . ($base === '' ? '' : $base) . '/install.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';

use ImWiki\Bootstrap\Application;

(new Application(__DIR__))->run();
