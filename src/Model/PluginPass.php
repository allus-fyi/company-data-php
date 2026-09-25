<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A short-lived pass for the plugin fields of a run's current step.
 *
 * {@see $plugins} lists `['id' => …, 'publicKey' => …]` — `publicKey` is null while the plugin's
 * description is missing or failed; {@see $specs} maps a slug to the plugin field's spec
 * `{plugin_id, field_type, snapshot, inputs}`. Calls go to `POST {forwarderUrl}/call`.
 */
final class PluginPass
{
    /**
     * @param list<array{id: string, publicKey: ?string}> $plugins
     * @param array<string,array<string,mixed>>           $specs
     * @param array<string,mixed>                         $raw
     */
    public function __construct(
        public readonly string $pass,
        public readonly string $forwarderUrl,
        public readonly array $plugins = [],
        public readonly array $specs = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string,mixed>|list<mixed>|string $body
     */
    public static function fromApi(array|string $body): self
    {
        $obj = is_array($body) ? $body : [];
        $plugins = [];
        foreach ((is_array($obj['plugins'] ?? null) ? $obj['plugins'] : []) as $p) {
            if (is_array($p) && isset($p['id'])) {
                $key = $p['public_key'] ?? null;
                $plugins[] = ['id' => (string) $p['id'], 'publicKey' => (is_string($key) && $key !== '') ? $key : null];
            }
        }
        $specs = [];
        foreach ((is_array($obj['specs'] ?? null) ? $obj['specs'] : []) as $slug => $spec) {
            if (is_array($spec)) {
                $specs[(string) $slug] = $spec;
            }
        }

        return new self(
            (string) ($obj['pass'] ?? ''),
            (string) ($obj['forwarder_url'] ?? ''),
            $plugins,
            $specs,
            $obj,
        );
    }

    public function publicKeyOf(string $pluginId): ?string
    {
        foreach ($this->plugins as $p) {
            if ($p['id'] === $pluginId) {
                return $p['publicKey'];
            }
        }

        return null;
    }
}
