<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            self::$data = [];
            return;
        }
        $data = require $path;
        self::$data = is_array($data) ? $data : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
