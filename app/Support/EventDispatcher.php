<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class EventDispatcher
{
    /** @var array<string,list<callable>> */
    private array $listeners = [];
    private static ?self $primary = null;

    public function __construct()
    {
        self::$primary ??= $this;
    }

    public static function primary():?self
    {
        return self::$primary;
    }

    public function on(string $event, callable $listener): void
    {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/',$event))throw new \InvalidArgumentException('Invalid event name.');
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }
}
