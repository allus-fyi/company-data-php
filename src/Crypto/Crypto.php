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
 * Decryption core — byte-identical across all six SDKs.
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
     * Load an OpenSSL-encrypted PKCS#8 PEM into an in-memory RSA private key,
     * pre-configured for OAEP-**SHA256** (MGF1-SHA256) unwrap of person values.
     *
     * The PEM is the OpenSSL-encrypted PKCS#8 you downloaded from the portal
     * (PBES2 = PBKDF2-HMAC-SHA256 + AES-256-CBC, ~100k iters). phpseclib's
     * PublicKeyLoader reads it given the passphrase; the key is never written
     * back to disk in plaintext.
     *
     * Config-only key handling: this is the single place a passphrase is used,
     * and it is driven by {@code Config::keyPassphrase} — never passed in by
     * application code.
     *
     * @throws DecryptError on a wrong passphrase / malformed PEM / non-RSA key.
     */
    public static function loadPrivateKey(string $encryptedPem, string $passphrase): RSAPrivateKey
    {
        try {
            $key = PublicKeyLoader::load($encryptedPem, $passphrase);
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
}
