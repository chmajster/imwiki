<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Logger
{
    public function __construct(private readonly string $logDir, private readonly bool $debug = false)
    {
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write('DEBUG', $message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0770, true);
        }
        foreach (['password', 'db_password', 'smtp_password', 'csrf', 'session_id'] as $secretKey) {
            unset($context[$secretKey]);
        }
        $line = sprintf("[%s] %s %s %s\n", gmdate('c'), $level, $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        @file_put_contents($this->logDir . '/imwiki.log', $line, FILE_APPEND | LOCK_EX);
    }
}
