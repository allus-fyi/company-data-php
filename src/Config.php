<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Errors\ConfigError;

/**
 * Configuration loading.
 *
 * Config-only key handling is a hard rule: **no SDK method ever takes a key,
 * passphrase, or secret as an argument.** Everything cryptographic — decrypting
 * the service PEM, decrypting field values, verifying the webhook HMAC,
 * unwrapping the account-key envelope — is driven entirely by this config. The
 * developer's only key responsibility is putting the right values here.
 *
 * A single JSON file holds everything; any field may be overridden by an
 * {@code ALLUS_*} env var, so secrets needn't live in the file.
 */
final class Config
{
    /**
     * Reserved webhook-map key under which a flat "webhook_secret" is stored.
     */
    public const SINGLE_WEBHOOK_KEY = '__single__';

    /**
     * Map from a Config field name to its {@code ALLUS_*} env-var override.
     * Secrets are the common overrides, but every field is overridable.
     *
     * @var array<string,string>
     */
    private const ENV_MAP = [
        'apiUrl' => 'ALLUS_API_URL',
        'clientId' => 'ALLUS_CLIENT_ID',
        'clientSecret' => 'ALLUS_CLIENT_SECRET',
        'servicePrivateKey' => 'ALLUS_SERVICE_PRIVATE_KEY',
        'keyPassphrase' => 'ALLUS_KEY_PASSPHRASE',
        'accountPrivateKey' => 'ALLUS_ACCOUNT_PRIVATE_KEY',
        'accountPassphrase' => 'ALLUS_ACCOUNT_PASSPHRASE',
        'cacheDir' => 'ALLUS_CACHE_DIR',
        'format' => 'ALLUS_FORMAT',
    ];

    /**
     * The exact snake_case JSON keys for each scalar field (pinned, so the
     * binder never defaults to camelCase). The env map above maps the same
     * fields to {@code ALLUS_*} names.
     *
     * @var array<string,string>
     */
    private const JSON_KEY = [
        'apiUrl' => 'api_url',
        'clientId' => 'client_id',
        'clientSecret' => 'client_secret',
        'servicePrivateKey' => 'service_private_key',
        'keyPassphrase' => 'key_passphrase',
        'accountPrivateKey' => 'account_private_key',
        'accountPassphrase' => 'account_passphrase',
        'cacheDir' => 'cache_dir',
        'format' => 'format',
    ];

    /** The flat single-webhook shortcut env override. */
    private const WEBHOOK_SECRET_ENV = 'ALLUS_WEBHOOK_SECRET';

    /** Required for any working client. */
    private const REQUIRED = [
        'apiUrl',
        'clientId',
        'clientSecret',
        'servicePrivateKey',
        'keyPassphrase',
    ];

    private const VALID_FORMATS = ['json', 'xml'];

    /**
     * @param array<string,string> $webhooks per-webhook HMAC secrets keyed by id
     *        (plus the {@see SINGLE_WEBHOOK_KEY} flat shortcut), normalized.
     */
    public function __construct(
        public readonly string $apiUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $servicePrivateKey,
        public readonly string $keyPassphrase,
        public readonly ?string $accountPrivateKey = null,
        public readonly ?string $accountPassphrase = null,
        public readonly array $webhooks = [],
        public readonly string $cacheDir = './allus-cache',
        public readonly string $format = 'json',
    ) {
    }

    /**
     * Load from a JSON file; env vars override file values.
     */
    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ConfigError("config file not found: {$path}");
        }
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigError("config file is not valid JSON: {$path}: {$e->getMessage()}");
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ConfigError("config file must be a JSON object: {$path}");
        }
        /** @var array<string,mixed> $data */
        return self::build($data);
    }

    /**
     * Build entirely from {@code ALLUS_*} env vars.
     */
    public static function fromEnv(): self
    {
        return self::build([]);
    }

    /**
     * Merge file values with env overrides, validate, and construct.
     *
     * @param array<string,mixed> $data
     */
    private static function build(array $data): self
    {
        $values = [];

        // Scalar fields: env var (if set) overrides the file value.
        foreach (self::ENV_MAP as $attr => $envName) {
            $env = self::env($envName);
            if ($env !== null) {
                $values[$attr] = $env;
            } else {
                $jsonKey = self::JSON_KEY[$attr];
                if (array_key_exists($jsonKey, $data) && $data[$jsonKey] !== null) {
                    $values[$attr] = $data[$jsonKey];
                }
            }
        }

        // Webhook secrets: the "webhooks" map plus the flat "webhook_secret"
        // shortcut (and its env override), normalized into a single dict.
        $webhooks = [];
        $fileWebhooks = $data['webhooks'] ?? null;
        if ($fileWebhooks !== null) {
            if (!is_array($fileWebhooks) || array_is_list($fileWebhooks)) {
                throw new ConfigError('"webhooks" must be an object mapping webhook id -> secret');
            }
            foreach ($fileWebhooks as $k => $v) {
                $webhooks[(string) $k] = (string) $v;
            }
        }

        $flatSecret = self::env(self::WEBHOOK_SECRET_ENV);
        if ($flatSecret === null && isset($data['webhook_secret'])) {
            $flatSecret = (string) $data['webhook_secret'];
        }
        if ($flatSecret !== null) {
            $webhooks[self::SINGLE_WEBHOOK_KEY] = (string) $flatSecret;
        }

        // Required fields (fail fast).
        $missing = [];
        foreach (self::REQUIRED as $name) {
            $v = $values[$name] ?? null;
            if ($v === null || $v === '') {
                $missing[] = self::JSON_KEY[$name];
            }
        }
        if ($missing !== []) {
            throw new ConfigError('missing required config field(s): ' . implode(', ', $missing));
        }

        // Validate the wire format if supplied.
        $format = $values['format'] ?? 'json';
        $format = strtolower((string) $format);
        if (!in_array($format, self::VALID_FORMATS, true)) {
            throw new ConfigError(sprintf(
                'invalid "format": %s (expected one of %s)',
                var_export($format, true),
                implode(', ', self::VALID_FORMATS),
            ));
        }

        return new self(
            apiUrl: (string) $values['apiUrl'],
            clientId: (string) $values['clientId'],
            clientSecret: (string) $values['clientSecret'],
            servicePrivateKey: (string) $values['servicePrivateKey'],
            keyPassphrase: (string) $values['keyPassphrase'],
            accountPrivateKey: isset($values['accountPrivateKey']) ? (string) $values['accountPrivateKey'] : null,
            accountPassphrase: isset($values['accountPassphrase']) ? (string) $values['accountPassphrase'] : null,
            webhooks: $webhooks,
            cacheDir: isset($values['cacheDir']) ? (string) $values['cacheDir'] : './allus-cache',
            format: $format,
        );
    }

    /**
     * Resolve the HMAC secret for a webhook id.
     *
     * Falls back to the single-webhook shortcut secret when there is no id or no
     * id-specific match. The webhook helpers read this — application code never
     * passes a secret in.
     */
    public function webhookSecret(?string $webhookId = null): ?string
    {
        if ($webhookId !== null && array_key_exists($webhookId, $this->webhooks)) {
            return $this->webhooks[$webhookId];
        }
        return $this->webhooks[self::SINGLE_WEBHOOK_KEY] ?? null;
    }

    /**
     * Read an env var, treating "" as unset (so an empty export doesn't shadow a
     * file value).
     */
    private static function env(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            return null;
        }
        return $v;
    }
}
