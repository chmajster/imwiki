<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/Autoloader.php';
ImWiki\Support\Autoloader::register(dirname(__DIR__));

use ImWiki\Security\Crypto;
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Security\SsrfGuard;
use ImWiki\Services\MarkdownService;

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

$markdown = new MarkdownService();
$html = '<h1>Dokumentacja</h1><p>Tekst <strong>ważny</strong> i <a href="https://example.com/docs">link</a>.</p><ul><li>Pierwszy</li><li>Drugi</li></ul><pre><code class="language-bash">echo test</code></pre>';
$md = $markdown->fromHtml($html);
$assert(str_contains($md, '# Dokumentacja'), 'markdown exports heading');
$assert(str_contains($md, '**ważny**'), 'markdown exports strong text');
$assert(str_contains($md, '[link](https://example.com/docs)'), 'markdown exports links');
$assert(str_contains($md, '- Pierwszy'), 'markdown exports lists');
$assert(str_contains($md, "```bash\necho test\n```"), 'markdown exports fenced code');
$roundtrip = $markdown->toHtml($md);
$assert(str_contains($roundtrip, '<h1>Dokumentacja</h1>'), 'markdown roundtrip heading');
$assert(str_contains($roundtrip, '<strong>ważny</strong>'), 'markdown roundtrip formatting');
$document = $markdown->exportDocument([
    'id' => 7,
    'title' => 'Test',
    'slug' => 'test',
    'space_key' => 'DOC',
    'owner_username' => 'admin',
    'status' => 'published',
    'review_date' => '2026-12-01',
    'updated_at' => '2026-09-09 00:00:00',
    'content' => $html,
], ['docs', 'test']);
$parsed = $markdown->parseDocument($document);
$assert(($parsed['meta']['title'] ?? null) === 'Test', 'frontmatter preserves title');
$assert(($parsed['meta']['labels'] ?? null) === ['docs', 'test'], 'frontmatter preserves label list');
$assert(($parsed['meta']['status'] ?? null) === 'published', 'frontmatter preserves status');
$assert(!str_contains($document, '<h1>Dokumentacja</h1>'), 'markdown export does not store normal rich text as HTML');

if ($failures) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK\n";
