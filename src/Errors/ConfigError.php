<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * Missing or invalid configuration (or key file) at construction (fail fast).
 *
 * Raised when the config file is missing/malformed, a required field is absent,
 * the wire format is invalid, or the service PEM is unreadable / its passphrase
 * is wrong (a bad key is a configuration problem, surfaced at client build, not a
 * runtime decrypt error).
 */
final class ConfigError extends \RuntimeException
{
}
