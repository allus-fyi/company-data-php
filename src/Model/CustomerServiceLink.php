<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/** One service the customer is connected to, inside a {@see CustomerConnection}. */
final class CustomerServiceLink
{
    /**
     * @param list<array<string,mixed>> $shared plaintext {slug,label,type,value}
     * @param list<array<string,mixed>> $mappings the customer's answered slots (metadata)
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $serviceLinkId,
        public readonly ?string $serviceId,
        public readonly ?string $serviceName,
        public readonly ?string $serviceCode,
        public readonly array $shared,
        public readonly array $mappings,
        public readonly mixed $pendingConsent,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $obj */
    public static function fromApi(array $obj): self
    {
        $shared = [];
        foreach (($obj['shared'] ?? []) as $s) {
            if (is_array($s)) {
                $shared[] = $s;
            }
        }
        $mappings = [];
        foreach (($obj['mappings'] ?? []) as $m) {
            if (is_array($m)) {
                $mappings[] = $m;
            }
        }
        $str = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return new self(
            serviceLinkId: $str($obj['service_link_id'] ?? $obj['id'] ?? null),
            serviceId: $str($obj['service_id'] ?? null),
            serviceName: $str($obj['service_name'] ?? $obj['name'] ?? null),
            serviceCode: $str($obj['service_code'] ?? $obj['share_code'] ?? null),
            shared: $shared,
            mappings: $mappings,
            pendingConsent: $obj['pending_consent'] ?? null,
            raw: $obj,
        );
    }
}
