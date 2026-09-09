<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class SlugService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = '',
    ) {}

    public function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if (function_exists('transliterator_transliterate')) {
            $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
        } elseif (function_exists('iconv')) {
            $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        }
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? 'page';
        $value = trim($value, '-');
        $value = mb_substr($value !== '' ? $value : 'page', 0, 240);
        return rtrim($value, '-') ?: 'page';
    }

    public function unique(int $spaceId, string $title, ?int $ignoreId = null): string
    {
        $base = $this->slugify($title);
        $slug = $base;
        $suffix = 2;
        while (true) {
            $sql = "SELECT COUNT(*) FROM `{$this->prefix}pages` WHERE space_id=? AND slug=? AND deleted_at IS NULL" . ($ignoreId !== null ? ' AND id<>?' : '');
            $stmt = $this->pdo->prepare($sql);
            $args = [$spaceId, $slug];
            if ($ignoreId !== null) {
                $args[] = $ignoreId;
            }
            $stmt->execute($args);
            if ((int)$stmt->fetchColumn() === 0) {
                return $slug;
            }
            $suffixText = '-' . $suffix++;
            $slug = mb_substr($base, 0, 255 - strlen($suffixText)) . $suffixText;
        }
    }
}
