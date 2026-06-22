<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests\Support;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;

/**
 * Loads the SHARED cross-language decryption test vector
 * ({@code sdks/testdata/decryption-vector.json}) and provides helpers to encrypt
 * arbitrary plaintexts into platform wrappers with the vector key's PUBLIC half —
 * so structured/date/account test values decrypt to known content via the SAME
 * crypto core (mirrors the Python test fixtures).
 */
final class Vector
{
    public const PATH = __DIR__ . '/../../testdata/decryption-vector.json';

    /**
     * @return array<string,mixed>
     */
    public static function load(): array
    {
        $raw = file_get_contents(self::PATH);
        if ($raw === false) {
            throw new \RuntimeException('could not read decryption vector: ' . self::PATH);
        }
        /** @var array<string,mixed> */
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Load the vector's private key, configured for OAEP-SHA256 (the person-value
     * contract).
     */
    public static function privateKey(): RSAPrivateKey
    {
        $v = self::load();
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($v['encrypted_private_key_pem'], $v['passphrase']);
        \assert($key instanceof RSAPrivateKey);
        return $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
    }

    /**
     * Encrypt a plaintext into a platform wrapper using the vector key's PUBLIC
     * half (RSA-OAEP-SHA256 + AES-256-GCM, 12B IV, 16B tag appended) — so it
     * decrypts to known content through the SDK's crypto core.
     *
     * @return array{_enc: int, k: string, iv: string, d: string}
     */
    public static function encryptForKey(string $plaintext): array
    {
        $pub = self::publicKeyOaepSha256();
        return self::wrap($pub, $plaintext);
    }

    private static function publicKeyOaepSha256(): RSAPublicKey
    {
        $pub = self::privateKey()->getPublicKey();
        \assert($pub instanceof RSAPublicKey);
        return $pub->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
    }

    /**
     * The vector key's PUBLIC half as base64 SPKI/DER — what {@code GET /api/keys}
     * returns as {@code public_key} (so per-person document encryption can fetch it).
     */
    public static function publicSpkiB64(): string
    {
        $pub = self::privateKey()->getPublicKey();
        \assert($pub instanceof RSAPublicKey);
        return base64_encode($pub->toString('PKCS8'));
    }

    /**
     * @return array{_enc: int, k: string, iv: string, d: string}
     */
    public static function wrap(RSAPublicKey $publicKey, string $plaintext): array
    {
        $aesKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        \assert($ct !== false);
        $k = $publicKey->encrypt($aesKey);
        return [
            '_enc' => 1,
            'k' => base64_encode($k),
            'iv' => base64_encode($iv),
            'd' => base64_encode($ct . $tag),
        ];
    }
}
