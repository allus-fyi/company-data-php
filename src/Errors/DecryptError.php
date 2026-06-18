<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * Wrapper malformed, wrong key, or GCM tag mismatch.
 *
 * Raised by the decryption core: a structurally bad {@code {"_enc":1,k,iv,d}}
 * wrapper, an RSA-OAEP unwrap that fails (wrong service key), or an AES-GCM tag
 * mismatch (corrupt data). In the changes pump a DecryptError on a buffered event
 * is dead-lettered immediately (re-decrypting can't help — see the pump).
 */
final class DecryptError extends \RuntimeException
{
}
