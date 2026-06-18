<?php

declare(strict_types=1);

namespace Allus\CompanyData\Pump;

/**
 * A no-op {@see Logger} (the default when none is wired).
 */
final class NullLogger implements Logger
{
    public function debug(string $message): void
    {
    }

    public function info(string $message): void
    {
    }

    public function warning(string $message): void
    {
    }

    public function error(string $message): void
    {
    }
}
