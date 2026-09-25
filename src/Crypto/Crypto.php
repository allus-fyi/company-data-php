<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

use Allus\CompanyData\Errors\DecryptError;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Decryption core.
 *
 * Every person value arrives as a ciphertext wrapper, encrypted **for the
 * service public key**; the SDK decrypts with the service private key. The
 * algorithm MUST match the platform's Web Crypto encryption exactly:
 *
 *     wrapper = {"_enc":1,
 *                "k":  base64(rsa_oaep_sha256(aesKey, servicePublicKey)),
 *                "iv": base64(iv12),
 *                "d":  base64(aes256gcm_ciphertext_with_tag)}
 *
 *     decrypt(wrapper, servicePrivateKey):
 *       aesKey    = RSA-OAEP(SHA-256, MGF1-SHA256) decrypt wrapper.k   # 32 bytes
 *       plaintext = AES-256-GCM decrypt wrapper.d with aesKey, iv=wrapper.iv
 *                   # the 16-byte GCM tag is the LAST 16 bytes of d
 *       return utf8(plaintext)
 *
 * PHP specifics: {@code openssl_private_decrypt} ONLY does OAEP-SHA1, so the inner
 * RSA-OAEP-**SHA256** unwrap uses **phpseclib3** ({@see PublicKeyLoader::load}
 * then {@code ->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')
 * ->withMGFHash('sha256')}). AES-256-GCM uses the openssl ext
 * ({@code openssl_decrypt(..., 'aes-256-gcm', ...)}), splitting the trailing
 * 16-byte tag off the ciphertext ourselves.
 */
final class Crypto
{
    /** GCM tag length (bytes) — appended to the AES-GCM ciphertext. */
    public const GCM_TAG_LEN = 16;

    /** GCM IV length (bytes). */
    public const GCM_IV_LEN = 12;

    /**
     * Load a PKCS#8 PEM into an in-memory RSA private key, pre-configured for
     * OAEP-**SHA256** (MGF1-SHA256) unwrap of person values.
     *
     * The platform's key downloads are OpenSSL-encrypted PKCS#8 PEMs (PBES2 =
     * PBKDF2-HMAC-SHA256 + AES-256-CBC, ~100k iters); phpseclib's PublicKeyLoader
     * reads one given the passphrase. An UNENCRYPTED PKCS#8 PEM loads too — a plugin
     * server's own key, for {@see pluginOpenRequest} — with the passphrase null or
     * empty. The key is never written back to disk in plaintext.
     *
     * Config-only key handling: the client roles use this only with the configured
     * passphrase — never one passed in by application code.
     *
     * @throws DecryptError on a wrong passphrase / malformed PEM / non-RSA key.
     */
    public static function loadPrivateKey(string $encryptedPem, ?string $passphrase): RSAPrivateKey
    {
        try {
            $key = PublicKeyLoader::load($encryptedPem, $passphrase === null || $passphrase === '' ? false : $passphrase);
        } catch (\Throwable $e) {
            // phpseclib throws NoKeyLoadedException for a wrong passphrase /
            // malformed PEM; surface it as a DecryptError.
            throw new DecryptError("could not load private key PEM: {$e->getMessage()}", 0, $e);
        }
        if (!$key instanceof RSAPrivateKey) {
            throw new DecryptError('PEM did not contain an RSA private key');
        }
        return self::asOaepSha256($key);
    }

    /**
     * Configure an RSA private key for OAEP-SHA256 (MGF1-SHA256) — the person
     * value contract. Pin BOTH the OAEP digest and MGF1 to SHA-256 (never accept
     * the SHA-1 default — PHP/phpseclib default to SHA-1).
     */
    public static function asOaepSha256(RSAPrivateKey $key): RSAPrivateKey
    {
        /** @var RSAPrivateKey $k */
        $k = $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        return $k;
    }

    /**
     * Configure an RSA private key for OAEP-**SHA1** (MGF1-SHA1) — the webhook
     * account-key envelope contract (OpenSSL's default OAEP, the only place the
     * platform uses SHA-1). Distinct from the SHA-256 person-value path.
     */
    public static function asOaepSha1(RSAPrivateKey $key): RSAPrivateKey
    {
        /** @var RSAPrivateKey $k */
        $k = $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha1')->withMGFHash('sha1');
        return $k;
    }

    /**
     * Decrypt a platform {@code {"_enc":1,k,iv,d}} wrapper → utf-8 plaintext string.
     *
     * For a *text* value the plaintext is the value itself. For a *binary* value
     * the plaintext is a JSON envelope STRING (photo:
     * {@code {"full":"data:...","thumb":...}}; document:
     * {@code {"file":"data:...","original_name":...}}) — NOT raw bytes. The full
     * binary-handle parse (envelope -> data-URI -> bytes) lives on
     * {@see BinaryHandle}; here we only ever decrypt to that envelope string.
     *
     * @param array<string,mixed>|string $wrapper the wrapper dict or its JSON string.
     * @param RSAPrivateKey $privateKey an OAEP-SHA256-configured key (from {@see loadPrivateKey}).
     *
     * @throws DecryptError on a malformed wrapper, the wrong key, or a GCM tag mismatch.
     */
    public static function decrypt(array|string $wrapper, RSAPrivateKey $privateKey): string
    {
        if (is_string($wrapper)) {
            try {
                $wrapper = json_decode($wrapper, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DecryptError('wrapper string is not valid JSON', 0, $e);
            }
            if (!is_array($wrapper)) {
                throw new DecryptError('wrapper must be a dict or a JSON object string');
            }
        }

        foreach (['k', 'iv', 'd'] as $fieldName) {
            if (!array_key_exists($fieldName, $wrapper)) {
                throw new DecryptError("wrapper missing required field '{$fieldName}'");
            }
        }

        $encKey = self::b64decode($wrapper['k'], 'k');
        $iv = self::b64decode($wrapper['iv'], 'iv');
        $ciphertextWithTag = self::b64decode($wrapper['d'], 'd');

        if (strlen($iv) !== self::GCM_IV_LEN) {
            throw new DecryptError(sprintf('iv must be %d bytes, got %d', self::GCM_IV_LEN, strlen($iv)));
        }
        if (strlen($ciphertextWithTag) < self::GCM_TAG_LEN) {
            throw new DecryptError('ciphertext too short to contain a GCM tag');
        }

        // 1) RSA-OAEP(SHA-256, MGF1-SHA256) unwrap the AES key. The key handed in
        //    is already OAEP-SHA256-configured by loadPrivateKey().
        try {
            $aesKey = @$privateKey->decrypt($encKey);
        } catch (\Throwable $e) {
            throw new DecryptError("RSA-OAEP unwrap failed (wrong key?): {$e->getMessage()}", 0, $e);
        }
        if (!is_string($aesKey) || strlen($aesKey) !== 32) {
            $len = is_string($aesKey) ? strlen($aesKey) : 0;
            throw new DecryptError("unwrapped AES key must be 32 bytes (AES-256), got {$len}");
        }

        // 2) AES-256-GCM decrypt. The 16-byte tag is the LAST 16 bytes of d.
        $plaintext = self::aesGcmDecrypt($ciphertextWithTag, $aesKey, $iv);
        if ($plaintext === false) {
            throw new DecryptError('AES-GCM tag mismatch (wrong key or corrupt data)');
        }

        if (!self::isValidUtf8($plaintext)) {
            throw new DecryptError('decrypted plaintext is not valid UTF-8');
        }
        return $plaintext;
    }

    /**
     * Load a base64 SPKI/DER public key (the platform's {@code GET /api/keys}
     * {@code public_key}) → an RSA public key configured for OAEP-**SHA256**
     * (MGF1-SHA256) — the per-person encryption contract.
     *
     * Config-only key handling does NOT apply to a RECIPIENT public key: it is not
     * a secret and is fetched live from the API per-recipient (never configured).
     * The SDK still never accepts a *private* key/passphrase as a method argument.
     *
     * @throws DecryptError on invalid base64, a malformed SPKI key, or a non-RSA key.
     */
    public static function loadPublicKey(string $spkiB64): RSAPublicKey
    {
        $der = base64_decode($spkiB64, strict: true);
        if ($der === false) {
            throw new DecryptError('recipient public_key is not valid base64');
        }
        try {
            $key = PublicKeyLoader::load($der);
        } catch (\Throwable $e) {
            throw new DecryptError("recipient public_key is not a valid SPKI key: {$e->getMessage()}", 0, $e);
        }
        if (!$key instanceof RSAPublicKey) {
            throw new DecryptError('recipient public_key is not an RSA public key');
        }
        // Pin BOTH the OAEP digest and MGF1 to SHA-256 (never the SHA-1 default).
        /** @var RSAPublicKey $k */
        $k = $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        return $k;
    }

    /**
     * Encrypt a UTF-8 string FOR a recipient RSA public key → a
     * {@code {"_enc":1,k,iv,d}} wrapper. The exact inverse of {@see decrypt()}:
     *
     *     aesKey  = 32 random bytes
     *     d       = AES-256-GCM(aesKey, iv=12 random bytes).encrypt(utf8(plaintext))  # tag appended
     *     k       = RSA-OAEP(SHA-256, MGF1-SHA256).encrypt(aesKey, publicKey)
     *
     * Used for EVERY per-person (targeted) document (json + file), independent of
     * is_private — broadcast docs stay plaintext.
     *
     * PHP specifics: {@code openssl_public_encrypt} ONLY does OAEP-SHA1, so the RSA
     * step uses **phpseclib3** (the {@see loadPublicKey}-configured key's
     * {@code ->encrypt()}). AES-256-GCM uses the openssl ext with the 16-byte tag
     * appended to the ciphertext (the platform layout).
     *
     * @param RSAPublicKey $publicKey an OAEP-SHA256-configured key (from {@see loadPublicKey}).
     *
     * @return array{_enc: int, k: string, iv: string, d: string}
     *
     * @throws DecryptError on an unexpected AES-GCM failure.
     */
    public static function encryptForPublicKey(string $plaintext, RSAPublicKey $publicKey): array
    {
        $aesKey = random_bytes(32);
        $iv = random_bytes(self::GCM_IV_LEN); // 12
        $tag = '';
        // AES-256-GCM: produce the 16-byte tag and APPEND it to the ciphertext (platform layout).
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LEN,
        );
        if ($ct === false) {
            throw new DecryptError('AES-256-GCM encryption failed');
        }
        // RSA-OAEP(SHA-256, MGF1-SHA256) wrap the AES key. The key handed in is
        // already OAEP-SHA256-configured by loadPublicKey().
        $encKey = $publicKey->encrypt($aesKey);
        if (!is_string($encKey)) {
            throw new DecryptError('RSA-OAEP key wrap failed');
        }
        return [
            '_enc' => 1,
            'k' => base64_encode($encKey),
            'iv' => base64_encode($iv),
            'd' => base64_encode($ct . $tag),
        ];
    }

    /**
     * The one-time-key bundle a flow run's `/generate` takes: the WHOLE answer map, sealed under a
     * key used once and never stored. `$answers` is `[slug => plaintext]` (a non-string value is
     * JSON-encoded). A random 32-byte AES-256-GCM key encrypts `JSON($answers)`; the result is
     * packed `iv(12) . ciphertext . tag(16)` and both halves are base64-encoded → `[otk, values]`.
     * The server evaluates every leaf-PDF condition, constant and `{{tag}}` over this map, so a slug
     * missing from it prints blank on the contract.
     *
     * @param array<string,mixed> $answers
     * @return array{otk: string, values: string}
     *
     * @throws DecryptError on an unexpected AES-GCM failure.
     */
    public static function oneTimeKeyBundle(array $answers): array
    {
        $strMap = [];
        foreach ($answers as $k => $v) {
            $strMap[$k] = is_string($v) ? $v : json_encode($v, JSON_THROW_ON_ERROR);
        }
        $payload = json_encode($strMap, JSON_THROW_ON_ERROR);
        $otk = random_bytes(32);
        $iv = random_bytes(self::GCM_IV_LEN);
        $tag = '';
        $ct = openssl_encrypt($payload, 'aes-256-gcm', $otk, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_LEN);
        if ($ct === false) {
            throw new DecryptError('AES-256-GCM encryption failed for flow generate payload');
        }

        return ['otk' => base64_encode($otk), 'values' => base64_encode($iv . $ct . $tag)];
    }

    /**
     * AES-256-GCM decrypt, splitting the trailing {@see GCM_TAG_LEN}-byte tag off
     * the ciphertext (the platform layout). Returns false on a tag mismatch.
     */
    public static function aesGcmDecrypt(string $ciphertextWithTag, string $aesKey, string $iv): string|false
    {
        $tag = substr($ciphertextWithTag, -self::GCM_TAG_LEN);
        $ct = substr($ciphertextWithTag, 0, -self::GCM_TAG_LEN);
        return openssl_decrypt($ct, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
    }

    /**
     * Strict base64 decode (mirrors the platform's base64 fields).
     *
     * @throws DecryptError when not a string or not valid base64.
     */
    public static function b64decode(mixed $value, string $fieldName): string
    {
        if (!is_string($value)) {
            throw new DecryptError("wrapper field '{$fieldName}' must be a base64 string");
        }
        $decoded = base64_decode($value, strict: true);
        if ($decoded === false) {
            throw new DecryptError("wrapper field '{$fieldName}' is not valid base64");
        }
        return $decoded;
    }

    private static function isValidUtf8(string $s): bool
    {
        // The platform plaintext is always UTF-8.
        return $s === '' || preg_match('//u', $s) === 1;
    }

    /**
     * SHA-256 of raw PDF bytes, lowercase hex — the plainSha256 a signable file document's
     * create call and every sign/accept act must agree on. Exposed so a caller can precompute
     * or verify it; createDocument calls this itself when a plainSha256 override is not given.
     */
    public static function computePlainSha256(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Verified fields: true iff sha256(salt ‖ plaintext) === expectedHash (hex). Consumers
     * recompute this from the plaintext they just decrypted and trust the verified flag only on a match.
     */
    public static function hashMatches(string $salt, string $expectedHash, string $plaintext): bool
    {
        if ($salt === '' || $expectedHash === '') {
            return false;
        }
        return hash_equals($expectedHash, hash('sha256', $salt . $plaintext));
    }

    // ── plugin sealing ─────────────────────────────────────────────────────────────

    /**
     * A fresh RSA-2048 reply key pair → [OAEP-SHA256-configured private key, base64 SPKI of the
     * public half]. A plugin seals its reply to the public half (the request's `reply_key`); only
     * the caller holding the private half can open it.
     *
     * @return array{0: RSAPrivateKey, 1: string}
     */
    public static function generateReplyKeyPair(): array
    {
        /** @var RSAPrivateKey $key */
        $key = RSA::createKey(2048);
        /** @var RSAPublicKey $public */
        $public = $key->getPublicKey();

        return [self::asOaepSha256($key), self::exportPublicKeySpki($public)];
    }

    /** A public key as base64 SPKI (DER) — the form every platform key travels in. */
    public static function exportPublicKeySpki(RSAPublicKey $publicKey): string
    {
        $pem = $publicKey->toString('PKCS8');

        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    /**
     * For a PLUGIN'S OWN SERVER: open the body of a `POST {base_url}/call` → the request.
     *
     * `$body` is the call's JSON body `{"request": "<wrapper string>"}` (raw or decoded);
     * `$privateKeyPem` is the plugin's own PKCS#8 PEM, encrypted (with `$passphrase`) or not
     * (`null`). The request carries `field_type`, `op`, `block`, `query`, `picks`, `values`,
     * `inputs` and the caller's `reply_key` — seal the answer to it with {@see pluginSealReply}.
     * This is a function for the plugin's server, never a call on the allme API.
     *
     * @param string|array<string,mixed> $body
     *
     * @return array<string,mixed>
     *
     * @throws DecryptError when the body, the wrapper or the key is not usable, or the plaintext
     *                      is not a JSON object. A plugin answers a request sealed to a key it no
     *                      longer holds with `409 {"error":"key_unknown"}`.
     */
    public static function pluginOpenRequest(string|array $body, string $privateKeyPem, ?string $passphrase): array
    {
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new DecryptError('plugin call body is not a JSON object');
            }
            $body = $decoded;
        }
        $request = $body['request'] ?? null;
        if (!is_string($request) && !is_array($request)) {
            throw new DecryptError("plugin call body has no 'request' wrapper");
        }
        $plaintext = self::decrypt($request, self::loadPrivateKey($privateKeyPem, $passphrase));
        $shape = json_decode($plaintext);
        if (!$shape instanceof \stdClass) {
            throw new DecryptError('plugin request plaintext is not a JSON object');
        }
        /** @var array<string,mixed> $opened */
        $opened = json_decode($plaintext, true);

        return $opened;
    }

    /**
     * For a PLUGIN'S OWN SERVER: seal a reply to the request's `reply_key` → the response body
     * `['reply' => '<wrapper string>']`.
     *
     * `$reply` is the reply plaintext — `{"options":[{id,label}],"more":bool}`,
     * `{"outputs":{key: value|null}}` or `{"picks_invalid":true}`. It is encoded as given: pass an
     * object (or `(object) []`) where the protocol wants a JSON object that may be empty.
     *
     * @param array<string,mixed>|object $reply
     *
     * @return array{reply: string}
     */
    public static function pluginSealReply(array|object $reply, string $replyKeySpki): array
    {
        $plaintext = json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $wrapper = self::encryptForPublicKey($plaintext, self::loadPublicKey($replyKeySpki));

        return ['reply' => json_encode($wrapper, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    }
}
