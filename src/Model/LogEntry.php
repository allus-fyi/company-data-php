<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A service activity-log entry — ops events only, never person data.
 */
final class LogEntry
{
    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $type,
        public readonly ?string $message,
        public readonly mixed $metadata,
        public readonly ?\DateTimeImmutable $at = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string,mixed> $obj
     */
    public static function fromApi(array $obj): self
    {
        return new self(
            type: isset($obj['type']) ? (string) $obj['type'] : null,
            message: isset($obj['message']) ? (string) $obj['message'] : null,
            metadata: $obj['metadata'] ?? null,
            at: Coerce::dateTime($obj['at'] ?? ($obj['created_at'] ?? null)),
            raw: $obj,
        );
    }

    /**
     * Parse the {@code /logs} response → a list of log entries.
     *
     * @param array<string,mixed>|list<mixed> $body
     *
     * @return list<self>
     */
    public static function listFromApi(array $body): array
    {
        if (array_is_list($body)) {
            $items = $body;
        } else {
            $items = $body['items'] ?? [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o);
            }
        }
        return $out;
    }
}
