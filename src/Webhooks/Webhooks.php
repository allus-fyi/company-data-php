<?php

declare(strict_types=1);

namespace Allus\CompanyData\Webhooks;

use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\WebhookError;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Util\Xml;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;

/**
 * Webhook receiver helpers.
 *
 * The lower-latency push alternative to polling the changes feed. The platform
 * delivers each change event to the company's configured webhook URL with:
 *
 * - {@code X-Allus-Webhook-Id}  — which webhook this is (selects the HMAC secret).
 * - {@code X-Allus-Signature}   — {@code HMAC-SHA256(rawBody, secret)} as lowercase
 *   hex (PHP {@code hash_hmac('sha256', body, secret)}).
 * - the body — the same slug-keyed {@see Change} shape as the pull feed, JSON or
 *   XML. If the webhook has {@code encrypt_payload} on, the body is REPLACED by a
 *   {@code {"_enc":1,...}} envelope encrypted to the company **account** key (and
 *   the HMAC is then over that envelope — the final body that was sent).
 *
 * All secrets/keys come from {@see Config}. **These helpers take NO
 * key or secret arguments** — only the raw body, the headers, the config, and (for
 * value typing) the same decrypt/type closures the {@see \Allus\CompanyData\Client}
 * already holds.
 *
 * Crypto note: the account-key envelope is wrapped with OpenSSL's DEFAULT OAEP
 * padding (MGF1-**SHA1**), NOT the SHA-256 wrapper used for person field values
 * (the account-key envelope uses {@code OPENSSL_PKCS1_OAEP_PADDING}). So
 * unwrapping the envelope uses an OAEP-SHA1
 * path here, while the inner field {@code value} (still a service-key wrapper)
 * decrypts with the normal SHA-256 {@see Crypto::decrypt()}.
 */
final class Webhooks
{
    private const HDR_WEBHOOK_ID = 'x-allus-webhook-id';
    private const HDR_SIGNATURE = 'x-allus-signature';
    private const ENC_MARKER = '_enc';

    /**
     * Verify a webhook against the SINGLE configured auth method.
     *
     * Mirrors the platform's per-webhook delivery auth (one method per webhook):
     *
     * - {@code hmac}   — recompute {@code HMAC-SHA256(rawBody, secret)} (secret
     *   selected by {@code X-Allus-Webhook-Id}) and constant-time-compare it to
     *   {@code X-Allus-Signature}.
     * - {@code bearer} — {@code Authorization} equals {@code Bearer <token>}.
     * - {@code basic}  — {@code Authorization} equals {@code Basic <base64(user:pass)>}.
     * - {@code header} — the configured custom header equals the configured value.
     * - {@code none}   — always {@code true} (explicit opt-out).
     *
     * All comparisons are constant-time. Returns {@code false} on a
     * missing/mismatched credential, or when no method is configured — never raises
     * for a bad credential (that is {@see handle()}'s job). Which method is used is
     * decided entirely by config ({@see Config::webhookAuthMethod()}); config
     * loading guarantees at most one is set.
     *
     * @param array<string,string> $headers
     */
    public static function verify(string $rawBody, array $headers, Config $config): bool
    {
        $method = $config->webhookAuthMethod();
        if ($method === null) {
            return false;
        }
        if ($method === 'none') {
            return true;
        }

        if ($method === 'bearer') {
            $got = self::header($headers, 'authorization');
            if ($got === null) {
                return false;
            }
            return hash_equals('Bearer ' . ($config->webhookBearerToken ?? ''), $got);
        }

        if ($method === 'basic') {
            $got = self::header($headers, 'authorization');
            if ($got === null) {
                return false;
            }
            /** @var array{username:string,password:string} $basic */
            $basic = $config->webhookBasic;
            $creds = $basic['username'] . ':' . $basic['password'];
            $token = base64_encode($creds);
            return hash_equals('Basic ' . $token, $got);
        }

        if ($method === 'header') {
            /** @var array{name:string,value:string} $header */
            $header = $config->webhookHeader;
            $got = self::header($headers, $header['name']);
            if ($got === null) {
                return false;
            }
            return hash_equals($header['value'], $got);
        }

        // method === 'hmac'
        $signature = self::header($headers, self::HDR_SIGNATURE);
        if ($signature === null || $signature === '') {
            return false;
        }
        $webhookId = self::header($headers, self::HDR_WEBHOOK_ID);
        $secret = $config->webhookSecret($webhookId);
        if ($secret === null || $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        // Constant-time compare (case-insensitive hex, like the platform's output).
        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Parse a webhook body → a typed {@see Change}.
     *
     * Does NOT verify the signature (use {@see handle()} for verify+parse). Handles
     * JSON and XML bodies, and an {@code encrypt_payload} account-key envelope: if
     * the (JSON) body is a {@code {"_enc":1,...}} wrapper, it is first unwrapped
     * with the account private key (OAEP-SHA1) into the inner serialized payload,
     * which is then parsed. The inner field {@code value} (a service-key wrapper)
     * is decrypted by the same factory the feed uses, so a webhook Change is
     * identical to a feed Change.
     *
     * @param array<string,string> $headers
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     * @param RSAPrivateKey|null $accountKey pre-loaded (the Client caches it once); loaded on
     *        demand from config when null (config-only key handling either way).
     *
     * @throws WebhookError
     */
    public static function parse(
        string $rawBody,
        array $headers,
        Config $config,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
        ?RSAPrivateKey $accountKey = null,
    ): Change {
        $payload = self::decodePayload($rawBody, $config, $accountKey);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new WebhookError('webhook payload is not a JSON/XML object');
        }
        /** @var array<string,mixed> $payload */
        return Change::fromApi($payload, $typeForSlug, $decryptValue, $binaryFetch);
    }

    /**
     * Verify + parse a webhook in one call.
     *
     * Raises {@see WebhookError} on a bad/unknown signature; otherwise returns the
     * typed {@see Change}. The typical one-liner inside a webhook route.
     *
     * @param array<string,string> $headers
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     *
     * @throws WebhookError
     */
    public static function handle(
        string $rawBody,
        array $headers,
        Config $config,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
        ?RSAPrivateKey $accountKey = null,
    ): Change {
        if (!self::verify($rawBody, $headers, $config)) {
            throw new WebhookError('webhook signature verification failed');
        }
        return self::parse($rawBody, $headers, $config, $typeForSlug, $decryptValue, $binaryFetch, $accountKey);
    }

    /**
     * Load the account private key from config ONCE (or {@code null} if not configured).
     *
     * Reused by the {@see \Allus\CompanyData\Client} so an {@code encrypt_payload}
     * webhook never re-reads the PEM + re-runs PBKDF2 (~100k iters) per request —
     * loaded a single time at client construction, exactly like the service key.
     * Returns null when no {@code account_private_key} is configured.
     *
     * @throws WebhookError on a read / passphrase / PEM problem.
     */
    public static function loadAccountKey(Config $config): ?RSAPrivateKey
    {
        if ($config->accountPrivateKey === null || $config->accountPrivateKey === '') {
            return null;
        }
        $pem = @file_get_contents($config->accountPrivateKey);
        if ($pem === false) {
            throw new WebhookError("could not read account_private_key PEM: {$config->accountPrivateKey}");
        }
        $passphrase = $config->accountPassphrase ?? '';
        try {
            $key = PublicKeyLoader::load($pem, $passphrase);
        } catch (\Throwable $e) {
            throw new WebhookError("could not load account private key: {$e->getMessage()}", 0, $e);
        }
        if (!$key instanceof RSAPrivateKey) {
            throw new WebhookError('account PEM did not contain an RSA private key');
        }
        return Crypto::asOaepSha1($key);
    }

    // ── payload decoding (JSON / XML / encrypt_payload envelope) ──────────────

    /**
     * Decode the raw body into the change dict, unwrapping an account envelope first.
     *
     * @return array<string,mixed>|list<mixed>|string
     *
     * @throws WebhookError
     */
    private static function decodePayload(string $rawBody, Config $config, ?RSAPrivateKey $accountKey): array|string
    {
        $text = trim($rawBody);

        // An encrypt_payload envelope is always JSON ({"_enc":1,...}). Detect +
        // unwrap it before anything else (the inner payload is then JSON or XML).
        if (str_starts_with($text, '{')) {
            $obj = json_decode($text, true);
            if (!is_array($obj)) {
                throw new WebhookError('webhook body is not valid JSON');
            }
            if (
                ($obj[self::ENC_MARKER] ?? null) === 1
                && array_key_exists('k', $obj)
                && array_key_exists('iv', $obj)
                && array_key_exists('d', $obj)
            ) {
                $inner = self::unwrapAccountEnvelope($obj, $config, $accountKey);
                return self::decodeInner($inner);
            }
            return $obj;
        }

        // Otherwise an XML body (the platform's <response> serialization).
        if (str_starts_with($text, '<')) {
            try {
                return Xml::parse($text);
            } catch (\Throwable $e) {
                throw new WebhookError("webhook body is not valid XML: {$e->getMessage()}", 0, $e);
            }
        }

        throw new WebhookError('webhook body is neither JSON nor XML');
    }

    /**
     * Parse the decrypted inner payload (JSON or XML).
     *
     * @return array<string,mixed>|list<mixed>|string
     *
     * @throws WebhookError
     */
    private static function decodeInner(string $innerText): array|string
    {
        $stripped = trim($innerText);
        if (str_starts_with($stripped, '<')) {
            try {
                return Xml::parse($stripped);
            } catch (\Throwable $e) {
                throw new WebhookError("decrypted webhook payload is not valid XML: {$e->getMessage()}", 0, $e);
            }
        }
        $obj = json_decode($stripped, true);
        if ($obj === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new WebhookError('decrypted webhook payload is not valid JSON: ' . json_last_error_msg());
        }
        return $obj;
    }

    // ── account-key envelope unwrap (OAEP-SHA1 — webhook-specific) ────────────

    /**
     * Decrypt an {@code encrypt_payload} envelope with the ACCOUNT key.
     *
     * The platform wraps the serialized payload to the company account PUBLIC key
     * using OpenSSL's default OAEP (MGF1-**SHA1**) + AES-256-GCM. The hash here is
     * SHA1 (NOT the SHA-256 used for person field values) — the account key is
     * webhook-only, so the difference is intentional.
     *
     * @param array<string,mixed> $envelope
     *
     * @throws WebhookError
     */
    private static function unwrapAccountEnvelope(array $envelope, Config $config, ?RSAPrivateKey $accountKey): string
    {
        $key = $accountKey ?? self::loadAccountKey($config);
        if ($key === null) {
            throw new WebhookError(
                'received an encrypt_payload webhook but no account_private_key is configured'
            );
        }
        return self::decryptOaepSha1($envelope, $key);
    }

    /**
     * RSA-OAEP(**SHA-1**, MGF1-SHA1) unwrap + AES-256-GCM decrypt → utf-8 string.
     *
     * Mirrors {@see Crypto::decrypt()} but with a SHA-1-configured key (the only
     * place the platform uses SHA-1 OAEP).
     *
     * @param array<string,mixed> $wrapper an OAEP-SHA1-configured account private key.
     *
     * @throws WebhookError
     */
    private static function decryptOaepSha1(array $wrapper, RSAPrivateKey $accountKey): string
    {
        $encKey = self::b64($wrapper['k'] ?? null, 'k');
        $iv = self::b64($wrapper['iv'] ?? null, 'iv');
        $ciphertextWithTag = self::b64($wrapper['d'] ?? null, 'd');

        if (strlen($iv) !== Crypto::GCM_IV_LEN) {
            throw new WebhookError(sprintf('envelope iv must be %d bytes, got %d', Crypto::GCM_IV_LEN, strlen($iv)));
        }
        if (strlen($ciphertextWithTag) < Crypto::GCM_TAG_LEN) {
            throw new WebhookError('envelope ciphertext too short to contain a GCM tag');
        }

        try {
            $aesKey = @$accountKey->decrypt($encKey);
        } catch (\Throwable $e) {
            throw new WebhookError("account-key envelope RSA-OAEP unwrap failed (wrong account key?): {$e->getMessage()}", 0, $e);
        }
        if (!is_string($aesKey) || strlen($aesKey) !== 32) {
            $len = is_string($aesKey) ? strlen($aesKey) : 0;
            throw new WebhookError("unwrapped envelope AES key must be 32 bytes, got {$len}");
        }

        $plaintext = Crypto::aesGcmDecrypt($ciphertextWithTag, $aesKey, $iv);
        if ($plaintext === false) {
            throw new WebhookError('account-key envelope AES-GCM tag mismatch');
        }
        return $plaintext;
    }

    /**
     * @throws WebhookError
     */
    private static function b64(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new WebhookError("envelope field '{$name}' must be a base64 string");
        }
        $decoded = base64_decode($value, strict: true);
        if ($decoded === false) {
            throw new WebhookError("envelope field '{$name}' is not valid base64");
        }
        return $decoded;
    }

    /**
     * Case-insensitive header lookup (frameworks normalize casing inconsistently).
     *
     * @param array<string,string|list<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $target = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $target) {
                // Some frameworks deliver headers as arrays of values.
                if (is_array($value)) {
                    $value = $value[0] ?? null;
                }
                return $value !== null ? (string) $value : null;
            }
        }
        return null;
    }
}
