<?php

declare(strict_types=1);

namespace Allus\CompanyData\Pump;

/**
 * Minimal logger seam for the pump.
 *
 * Kept tiny + dependency-free; a PSR-3 logger can be adapted with a thin wrapper.
 * The default is {@see NullLogger} (no output); {@see FileLogger} appends lines to
 * a file. Pass your own to {@see \Allus\CompanyData\Client::__construct()}.
 */
interface Logger
{
    public function debug(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;

    public function error(string $message): void;
}
