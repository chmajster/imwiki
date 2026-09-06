<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register(dirname(__DIR__));

use ImWiki\Security\Crypto;
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Security\SessionIdRotationPolicy;
use ImWiki\Security\SsrfGuard;
use ImWiki\Support\Config;
use ImWiki\Support\Url;

$failures = [];
$assert = static function (bool $ok, string $name) use (&$failures): void {
    if (!$ok) { $failures[] = $name; }
};

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION = [];
$token = Csrf::token();
$assert(strlen($token) === 64, 'csrf token length');
$assert(Csrf::validate($token), 'csrf accepts current token');
$assert(!Csrf::validate(str_repeat('0', 64)), 'csrf rejects foreign token');

$crypto = new Crypto(str_repeat('s', 64));
$plain = 'sekret-' . bin2hex(random_bytes(8));
$cipher = $crypto->encrypt($plain);
$assert($cipher !== $plain, 'crypto does not expose plaintext');
$assert($crypto->decrypt($cipher) === $plain, 'crypto roundtrip');

$dirty = '<script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(2)"><p>OK</p>';
$clean = Html::sanitizeRichText($dirty);
$assert(!str_contains(strtolower($clean), '<script'), 'sanitizer removes script');
$assert(!str_contains(strtolower($clean), 'onerror'), 'sanitizer removes event handlers');
$assert(!str_contains(strtolower($clean), 'javascript:'), 'sanitizer removes javascript scheme');
$assert(str_contains($clean, 'OK'), 'sanitizer keeps safe text');

$guard = new SsrfGuard();
foreach (['http://127.0.0.1/x','http://localhost/x','http://169.254.169.254/latest/meta-data'] as $url) {
    try { $guard->validate($url); $assert(false, 'ssrf rejects ' . $url); } catch (Throwable) { $assert(true, 'ssrf rejects ' . $url); }
}

$now=1_800_000_000;
$assert(!SessionIdRotationPolicy::due([], $now), 'anonymous session does not rotate');
$assert(!SessionIdRotationPolicy::due(['user_id'=>1,'authenticated_at'=>$now-899], $now), 'active session does not rotate before interval');
$assert(SessionIdRotationPolicy::due(['user_id'=>1,'authenticated_at'=>$now-900], $now), 'active session rotates at interval boundary');
$assert(!SessionIdRotationPolicy::due(['user_id'=>1,'authenticated_at'=>$now-3600,'session_regenerated_at'=>$now-120], $now), 'recent rotation prevents repeated regeneration');
$assert(SessionIdRotationPolicy::due(['user_id'=>1,'authenticated_at'=>$now-3600,'session_regenerated_at'=>$now-901], $now), 'long active session rotates again after interval');

$oldScript=$_SERVER['SCRIPT_NAME']??null;
$configFile=tempnam(sys_get_temp_dir(),'imwiki-config-');
file_put_contents($configFile,"<?php return ['app'=>['base_path'=>'']];\n");
Config::load($configFile);$_SERVER['SCRIPT_NAME']='/login';
$assert(Url::basePath()==='', 'configured root base path overrides rewritten script name');
file_put_contents($configFile,"<?php return ['app'=>['base_path'=>'/wiki']];\n");
Config::load($configFile);$_SERVER['SCRIPT_NAME']='/wiki/login';
$assert(Url::basePath()==='/wiki', 'configured subdirectory base path is stable');
unlink($configFile);Config::load('/nonexistent-imwiki-test-config.php');
if($oldScript===null)unset($_SERVER['SCRIPT_NAME']);else $_SERVER['SCRIPT_NAME']=$oldScript;

if ($failures) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK\n";
