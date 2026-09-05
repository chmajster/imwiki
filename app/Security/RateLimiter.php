<?php
declare(strict_types=1);

namespace ImWiki\Security;

final class RateLimiter
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }

    public function tooManyAttempts(string $key, int $limit, int $windowSeconds): bool
    {
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $data = ['start' => $now, 'count' => 0];
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $data = $decoded + $data;
            }
        }
        if (($data['start'] + $windowSeconds) <= $now) {
            $data = ['start' => $now, 'count' => 0];
        }
        if ((int) $data['count'] >= $limit) {
            return true;
        }
        $data['count'] = (int) $data['count'] + 1;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return false;
    }
}
