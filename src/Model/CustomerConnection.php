<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * Customer-role connection model (b2b).
 *
 * A CUSTOMER is the connecting company consuming/answering another company's service.
 * Its {@code GET /api/company-connections} payload is one row per company↔company pair,
 * with the plaintext company profile + per-service shared values, the customer's own
 * answered mappings (metadata only), pending consents, and any issued documents.
 *
 * Thin wrapper over the raw API dict; the plaintext fields are exposed directly and
 * {@see $raw} is always kept.
 */
final class CustomerConnection
{
    /**
     * @param list<array<string,mixed>> $companyProfile plaintext {slug,label,type,value}
     * @param list<CustomerServiceLink> $services
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $companyUserId,
        public readonly ?string $companyName,
        public readonly ?string $companyCode,
        public readonly ?string $customerType,
        public readonly array $companyProfile,
        public readonly array $services,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $obj */
    public static function fromApi(array $obj): self
    {
        $company = is_array($obj['company'] ?? null) ? $obj['company'] : [];
        $services = [];
        foreach (($obj['services'] ?? []) as $s) {
            if (is_array($s)) {
                $services[] = CustomerServiceLink::fromApi($s);
            }
        }
        $profile = [];
        foreach (($obj['company_profile'] ?? []) as $p) {
            if (is_array($p)) {
                $profile[] = $p;
            }
        }

        return new self(
            id: self::str($obj['id'] ?? $obj['company_connection_id'] ?? null),
            companyUserId: self::str($obj['company_user_id'] ?? $company['user_id'] ?? null),
            companyName: self::str($obj['company_name'] ?? $company['display_name'] ?? null),
            companyCode: self::str($obj['company_code'] ?? $company['share_code'] ?? null),
            customerType: self::str($obj['customer_type'] ?? null),
            companyProfile: $profile,
            services: $services,
            raw: $obj,
        );
    }

    /**
     * @param mixed $body
     * @return list<self>
     */
    public static function listFromApi(mixed $body): array
    {
        $items = [];
        if (is_array($body)) {
            $items = $body['connections'] ?? $body['items'] ?? (array_is_list($body) ? $body : []);
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o);
            }
        }
        return $out;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
