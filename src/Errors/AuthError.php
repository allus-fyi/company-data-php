<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * The {@code client_credentials} token fetch or refresh failed.
 *
 * Raised when {@code /oauth2/token} rejects the credentials (bad client id/secret,
 * revoked client), or when a 401 mid-flight survives the one automatic
 * refresh-and-retry.
 */
final class AuthError extends \RuntimeException
{
}
