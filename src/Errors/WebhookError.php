<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * Signature verification failed, or a webhook envelope couldn't be unwrapped /
 * parsed.
 */
final class WebhookError extends \RuntimeException
{
}
