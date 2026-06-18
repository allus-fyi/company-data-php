<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * Field-type groupings used to type a decrypted value.
 */
final class FieldTypes
{
    /** Decrypted plaintext is a JSON object → a parsed assoc array. */
    public const STRUCTURED = ['address', 'bank', 'creditcard'];

    /** Value is a lazy binary handle (served as a value_url). */
    public const BINARY = ['photo', 'document', 'legal_document'];

    /** Decrypted plaintext is an ISO date. */
    public const DATE = ['date', 'date_of_birth'];
}
