<?php
declare(strict_types=1);

namespace ImWiki\Security;

final class SessionIdRotationPolicy
{
    public const INTERVAL_SECONDS = 900;

    public static function due(array $session, int $now): bool
    {
        if (!isset($session['user_id'])) return false;
        $last = (int)($session['session_regenerated_at'] ?? $session['authenticated_at'] ?? $now);
        return $last > 0 && ($now - $last) >= self::INTERVAL_SECONDS;
    }
}
