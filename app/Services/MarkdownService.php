<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Html;

final class MarkdownService
{
    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $out = [];
        $inCode = false;
        $code = [];
        $listType = null;
        $lang = '';

        $closeList = static function () use (&$out, &$listType): void {
            if ($listType !== null) {
                $out[] = '</' . $listType . '>';
                $listType = null;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^```([a-zA-Z0-9_+.-]*)\s*$/', $line, $match)) {
                if (!$inCode) {
                    $closeList();
                    $inCode = true;
                    $code = [];
                    $lang = $match[1] ?? '';
                } else {
                    $class = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';
                    $out[] = '<pre><code' . $class . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                    $inCode = false;
                    $lang = '';
                }
                continue;
            }

            if ($inCode) {
                $code[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $closeList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match)) {
                $closeList();
                $level = strlen($match[1]);
                $out[] = '<h' . $level . '>' . $this->inline($match[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^[-*_]{3,}\s*$/', $line)) {
                $closeList();
                $out[] = '<hr>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $match)) {
                $closeList();
                $out[] = '<blockquote><p>' . $this->inline($match[1]) . '</p></blockquote>';
                continue;
            }

            if (preg_match('/^[-*+]\s+(.+)$/', $line, $match)) {
                $this->ensureList('ul', $out, $listType);
                $out[] = '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $match)) {
                $this->ensureList('ol', $out, $listType);
                $out[] = '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }

            $closeList();
            if (str_starts_with(ltrim($line), '<')) {
                $out[] = $line;
            } else {
                $out[] = '<p>' . $this->inline($line) . '</p>';
            }
        }

        if ($inCode) {
            $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }
        $closeList();

        return Html::sanitizeRichText(implode("\n", $out));
    }

    public function fromHtml(string $html): string
    {
        $html = Html::sanitizeRichText($html);
        $blocks = [];

        $html = preg_replace_callback(
            '#<pre(?:\s+class="[^"]*")?>\s*<code(?:\s+class="language-([a-zA-Z0-9_+.-]+)")?>(.*?)</code>\s*</pre>#is',
            static function (array $match) use (&$blocks): string {
                $lang = $match[1] ?? '';
                $code = html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $code = rtrim(str_replace(["\r\n", "\r"], "\n", $code), "\n");
                $key = '@@IMWIKI_BLOCK_' . count($blocks) . '@@';
                $blocks[$key] = "```{$lang}\n{$code}\n```";
                return "\n{$key}\n";
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#<(table|details)\b[^>]*>.*?</\1>#is',
            static function (array $match) use (&$blocks): string {
                $key = '@@IMWIKI_BLOCK_' . count($blocks) . '@@';
                $blocks[$key] = trim($match[0]);
                return "\n{$key}\n";
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback('#<img\b[^>]*src="([^"]+)"[^>]*>#i', static function (array $match): string {
            $tag = $match[0];
            $src = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $alt = '';
            if (preg_match('/\balt="([^"]*)"/i', $tag, $altMatch)) {
                $alt = html_entity_decode($altMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return '![' . $alt . '](' . $src . ')';
        }, $html) ?? $html;

        $html = preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', static function (array $match): string {
            $label = html_entity_decode(trim(strip_tags($match[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '[' . $label . '](' . $href . ')';
        }, $html) ?? $html;

        $html = preg_replace('#<(strong|b)>(.*?)</\1>#is', '**$2**', $html) ?? $html;
        $html = preg_replace('#<(em|i)>(.*?)</\1>#is', '*$2*', $html) ?? $html;
        $html = preg_replace('#<s>(.*?)</s>#is', '~~$1~~', $html) ?? $html;
        $html = preg_replace('#<code>(.*?)</code>#is', '`$1`', $html) ?? $html;

        for ($level = 1; $level <= 6; $level++) {
            $pattern = '#<h' . $level . '\b[^>]*>(.*?)</h' . $level . '>#is';
            $prefix = str_repeat('#', $level);
            $html = preg_replace_callback($pattern, static fn(array $match): string => "\n{$prefix} " . trim(strip_tags($match[1])) . "\n", $html) ?? $html;
        }

        $html = preg_replace_callback('#<blockquote\b[^>]*>(.*?)</blockquote>#is', static function (array $match): string {
            $text = trim(preg_replace('#</?p\b[^>]*>#i', '', $match[1]) ?? $match[1]);
            $text = html_entity_decode(trim(strip_tags($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return "\n> " . str_replace("\n", "\n> ", $text) . "\n";
        }, $html) ?? $html;

        $html = preg_replace_callback('#<ul\b[^>]*>(.*?)</ul>#is', static function (array $match): string {
            $items = preg_replace_callback('#<li\b[^>]*>(.*?)</li>#is', static function (array $item): string {
                return '- ' . trim(strip_tags($item[1])) . "\n";
            }, $match[1]) ?? '';
            return "\n{$items}\n";
        }, $html) ?? $html;

        $html = preg_replace_callback('#<ol\b[^>]*>(.*?)</ol>#is', static function (array $match): string {
            $index = 0;
            $items = preg_replace_callback('#<li\b[^>]*>(.*?)</li>#is', static function (array $item) use (&$index): string {
                $index++;
                return $index . '. ' . trim(strip_tags($item[1])) . "\n";
            }, $match[1]) ?? '';
            return "\n{$items}\n";
        }, $html) ?? $html;

        $html = preg_replace('#<hr\s*/?>#i', "\n---\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "  \n", $html) ?? $html;
        $html = preg_replace('#</p\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<p\b[^>]*>#i', '', $html) ?? $html;
        $html = preg_replace('#</div\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<div\b[^>]*>#i', '', $html) ?? $html;
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($blocks as $key => $value) {
            $html = str_replace($key, $value, $html);
        }

        $html = preg_replace("/[ \t]+\n/u", "\n", $html) ?? $html;
        $html = preg_replace("/\n{3,}/u", "\n\n", $html) ?? $html;
        return trim($html) . "\n";
    }

    public function exportDocument(array $page, array $labels = []): string
    {
        $front = [
            'title' => $page['title'],
            'id' => (int)$page['id'],
            'slug' => $page['slug'],
            'space' => $page['space_key'] ?? null,
            'owner' => $page['owner_username'] ?? null,
            'status' => $page['status'] ?? 'published',
            'review_date' => $page['review_date'] ?? null,
            'labels' => $labels,
            'updated_at' => $page['updated_at'] ?? null,
        ];

        $yaml = "---\n";
        foreach ($front as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $yaml .= $key . ': [' . implode(', ', array_map(fn($item) => $this->yamlScalar((string)$item), $value)) . "]\n";
            } else {
                $yaml .= $key . ': ' . $this->yamlScalar((string)$value) . "\n";
            }
        }

        return $yaml . "---\n\n" . $this->fromHtml((string)$page['content']);
    }

    public function parseDocument(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $meta = [];
        $body = $raw;

        if (str_starts_with($raw, "---\n") && preg_match('/^---\n(.*?)\n---\n(.*)$/s', $raw, $match)) {
            foreach (explode("\n", $match[1]) as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode(':', $line, 2));
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
                    continue;
                }
                $meta[$key] = $this->parseScalar($value);
            }
            $body = $match[2];
        }

        return ['meta' => $meta, 'html' => $this->toHtml($body), 'markdown' => $body];
    }

    private function ensureList(string $type, array &$out, ?string &$listType): void
    {
        if ($listType === $type) {
            return;
        }
        if ($listType !== null) {
            $out[] = '</' . $listType . '>';
        }
        $out[] = '<' . $type . '>';
        $listType = $type;
    }

    private function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/!\[([^\]]*)\]\((https?:\/\/[^\s)]+|\/[^\s)]*|\.\.?\/[^\s)]*)\)/', '<img src="$2" alt="$1">', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/', '<s>$1</s>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/[^\s)]*|\.\.?\/[^\s)]*)\)/', '<a href="$2">$1</a>', $text) ?? $text;
        return $text;
    }

    private function yamlScalar(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === 'null' || $value === '~') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inside = trim(substr($value, 1, -1));
            if ($inside === '') {
                return [];
            }
            $items = str_getcsv($inside, ',', '"', '\\');
            return array_values(array_map(static fn(string $item): string => trim($item), $items));
        }
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return stripcslashes(substr($value, 1, -1));
        }
        return $value;
    }
}
