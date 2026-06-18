<?php

declare(strict_types=1);

namespace Allus\CompanyData\Pump;

/**
 * A simple line-appending {@see Logger} — writes timestamped, level-prefixed
 * lines to a file (or {@code php://stderr} by default).
 *
 * Lines never contain decrypted plaintext: the pump logs ids and counts only.
 */
final class FileLogger implements Logger
{
    public function __construct(
        private readonly string $path = 'php://stderr',
        private readonly string $minLevel = 'info',
    ) {
    }

    /** @var array<string,int> */
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    private function log(string $level, string $message): void
    {
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->minLevel] ?? 20)) {
            return;
        }
        $line = sprintf("%s [%s] %s\n", date('c'), strtoupper($level), $message);
        @file_put_contents($this->path, $line, FILE_APPEND);
    }
}
