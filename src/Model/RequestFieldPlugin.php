<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * The plugin a plugin request row or flow row asks through.
 */
final class RequestFieldPlugin
{
    /**
     * @param array<string,mixed>|null $snapshot the field type's frozen description:
     *                                           {plugin_name, host, label, blocks, inputs, outputs}
     * @param array<string,mixed>      $raw
     */
    public function __construct(
        public readonly ?string $pluginName,
        public readonly ?string $fieldType,
        public readonly ?array $snapshot = null,
        public readonly array $raw = [],
    ) {
    }

    /** Parse-permissive: anything but an object is "no plugin". */
    public static function fromApi(mixed $value): ?self
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        $snapshot = $value['snapshot'] ?? null;

        return new self(
            pluginName: isset($value['plugin_name']) ? (string) $value['plugin_name'] : null,
            fieldType: isset($value['field_type']) ? (string) $value['field_type'] : null,
            snapshot: is_array($snapshot) ? $snapshot : null,
            raw: $value,
        );
    }
}
