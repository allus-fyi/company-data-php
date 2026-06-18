<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;

/**
 * Decryption core tests — the cross-language parity gate.
 *
 * Proves the PHP decryptor reproduces the SHARED test vector
 * ({@code sdks/testdata/decryption-vector.json}): PEM-load (PBES2 / PBKDF2-SHA256
 * / AES-256-CBC, 100k iters), text decrypt → known plaintext, and binary decrypt
 * → envelope → inner-bytes hash. Includes an INDEPENDENT openssl-CLI + phpseclib
 * cross-check (anti-circularity) so the wrapper is proven platform-correct, not
 * merely self-consistent with Crypto.php.
 */
final class CryptoTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $vector;
    private static RSAPrivateKey $key;

    public static function setUpBeforeClass(): void
    {
        self::$vector = Vector::load();
        self::$key = Crypto::loadPrivateKey(
            self::$vector['encrypted_private_key_pem'],
            self::$vector['passphrase'],
        );
    }

    // ── PEM load ───────────────────────────────────────────────────────────

    public function testLoadPrivateKeyFromPbes2Pem(): void
    {
        $key = Crypto::loadPrivateKey(
            self::$vector['encrypted_private_key_pem'],
            self::$vector['passphrase'],
        );
        self::assertSame(2048, $key->getLength());
    }

    public function testLoadPrivateKeyWrongPassphraseRaises(): void
    {
        $this->expectException(DecryptError::class);
        Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], 'the-wrong-passphrase');
    }

    // ── self-consistent decryption ────────────────────────────────────────

    public function testDecryptTextWrapperMatchesPlaintext(): void
    {
        $plaintext = Crypto::decrypt(self::$vector['text']['wrapper'], self::$key);
        self::assertSame(self::$vector['text']['plaintext'], $plaintext);
    }

    public function testDecryptAcceptsWrapperAsJsonString(): void
    {
        $wrapperStr = json_encode(self::$vector['text']['wrapper'], JSON_THROW_ON_ERROR);
        self::assertSame(self::$vector['text']['plaintext'], Crypto::decrypt($wrapperStr, self::$key));
    }

    public function testDecryptBinaryWrapperToEnvelopeAndInnerBytes(): void
    {
        // Decrypting a binary wrapper yields a JSON envelope STRING.
        $envelopeJson = Crypto::decrypt(self::$vector['binary']['wrapper'], self::$key);
        self::assertSame(
            self::$vector['binary']['decrypted_json_sha256'],
            hash('sha256', $envelopeJson),
        );

        // BinaryHandle parses the envelope → base64-decodes the "full"/"file"
        // data-URI payload → the inner file bytes.
        $inner = BinaryHandle::parseEnvelopeBytes($envelopeJson);
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $inner));

        // And via the handle's public ->bytes() entry point.
        $handle = new BinaryHandle(envelopeJson: $envelopeJson);
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $handle->bytes()));
    }

    // ── error paths ──────────────────────────────────────────────────────

    public function testDecryptTagMismatchRaises(): void
    {
        $bad = self::$vector['text']['wrapper'];
        $raw = base64_decode($bad['d'], true);
        self::assertNotFalse($raw);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\xFF"; // corrupt the last tag byte
        $bad['d'] = base64_encode($raw);
        $this->expectException(DecryptError::class);
        Crypto::decrypt($bad, self::$key);
    }

    public function testDecryptMissingFieldRaises(): void
    {
        $this->expectException(DecryptError::class);
        Crypto::decrypt(['_enc' => 1, 'k' => 'AAAA', 'iv' => 'AAAA'], self::$key); // no "d"
    }

    public function testDecryptBadBase64Raises(): void
    {
        $bad = self::$vector['text']['wrapper'];
        $bad['k'] = 'not valid base64 !!!';
        $this->expectException(DecryptError::class);
        Crypto::decrypt($bad, self::$key);
    }

    public function testDecryptWrongIvLengthRaises(): void
    {
        $bad = self::$vector['text']['wrapper'];
        $bad['iv'] = base64_encode(random_bytes(16)); // 16, not 12
        $this->expectException(DecryptError::class);
        Crypto::decrypt($bad, self::$key);
    }

    public function testParseEnvelopeWithoutFullOrFileRaises(): void
    {
        $this->expectException(DecryptError::class);
        BinaryHandle::parseEnvelopeBytes(json_encode(['thumb' => 'x'], JSON_THROW_ON_ERROR));
    }

    // ── BinaryHandle::save() is atomic ──────────────────────────────────────

    public function testBinaryHandleSaveWritesBytesAndCount(): void
    {
        $envelopeJson = Crypto::decrypt(self::$vector['binary']['wrapper'], self::$key);
        $handle = new BinaryHandle(envelopeJson: $envelopeJson);
        $dir = sys_get_temp_dir() . '/allus-save-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $out = $dir . '/out.bin';
            $n = $handle->save($out);
            $data = file_get_contents($out);
            self::assertNotFalse($data);
            self::assertSame($n, strlen($data));
            self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $data));
        } finally {
            @unlink($dir . '/out.bin');
            @rmdir($dir);
        }
    }

    public function testBinaryHandleLazyFetchAndDecrypt(): void
    {
        // The fetch callback returns the encrypted wrapper for the slot.
        $captured = [];
        $fetch = function (string $url) use (&$captured): array {
            $captured['url'] = $url;
            return self::$vector['binary']['wrapper'];
        };
        $decrypt = fn (array|string $w): string => Crypto::decrypt($w, self::$key);

        $handle = new BinaryHandle(
            valueUrl: 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file',
            fetch: $fetch,
            decrypt: $decrypt,
        );
        self::assertArrayNotHasKey('url', $captured); // not fetched until ->bytes()

        $data = $handle->bytes();
        self::assertStringEndsWith('/slots/sf-9/file', $captured['url']);
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $data));

        // cached — a second call does not re-fetch (the captured url is unchanged).
        $captured['url'] = 'STALE';
        $handle->bytes();
        self::assertSame('STALE', $captured['url']);
    }

    // ── anti-circularity: independent openssl + phpseclib cross-check ───────

    public function testIndependentOpensslCrosscheck(): void
    {
        $opensslPath = trim((string) shell_exec('command -v openssl 2>/dev/null'));
        if ($opensslPath === '') {
            self::markTestSkipped('openssl CLI required for the independent cross-check');
        }
        self::assertSame(self::$vector['text']['plaintext'], self::independentDecryptText(self::$vector));
    }

    /**
     * Decrypt the vector's text wrapper WITHOUT this SDK's Crypto class.
     *
     * OpenSSL CLI: decrypt the PBES2 PEM + RSA-OAEP-SHA256 unwrap `k`.
     * PHP openssl ext: AES-256-GCM decrypt `d` (tag = last 16 bytes). Proves the
     * wrapper is platform-correct, not merely self-consistent with Crypto.php.
     *
     * @param array<string,mixed> $vector
     */
    private static function independentDecryptText(array $vector): string
    {
        $w = $vector['text']['wrapper'];
        $tmp = sys_get_temp_dir() . '/allus-xcheck-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $pemPath = "{$tmp}/key.pem";
            $plainPem = "{$tmp}/key_plain.pem";
            $kPath = "{$tmp}/k.bin";
            $aesPath = "{$tmp}/aeskey.bin";

            file_put_contents($pemPath, $vector['encrypted_private_key_pem']);
            file_put_contents($kPath, base64_decode($w['k'], true));

            // 1) OpenSSL: decrypt the PBES2 PKCS#8 PEM with the passphrase.
            self::runCli([
                'openssl', 'pkcs8', '-in', $pemPath,
                '-passin', 'pass:' . $vector['passphrase'],
                '-out', $plainPem,
            ]);
            // 2) OpenSSL: RSA-OAEP-SHA256 (MGF1-SHA256) unwrap the AES key.
            self::runCli([
                'openssl', 'pkeyutl', '-decrypt', '-inkey', $plainPem,
                '-pkeyopt', 'rsa_padding_mode:oaep',
                '-pkeyopt', 'rsa_oaep_md:sha256',
                '-pkeyopt', 'rsa_mgf1_md:sha256',
                '-in', $kPath, '-out', $aesPath,
            ]);
            $aesKey = file_get_contents($aesPath);
            self::assertNotFalse($aesKey);
            self::assertSame(32, strlen($aesKey));

            // 3) PHP openssl ext (independent of Crypto::decrypt): AES-256-GCM.
            $d = base64_decode($w['d'], true);
            $iv = base64_decode($w['iv'], true);
            $tag = substr($d, -16);
            $ct = substr($d, 0, -16);
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
            self::assertNotFalse($pt);
            return $pt;
        } finally {
            array_map('unlink', glob("{$tmp}/*") ?: []);
            @rmdir($tmp);
        }
    }

    /** @param list<string> $argv */
    private static function runCli(array $argv): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, "command failed: {$cmd}\n" . implode("\n", $out));
    }
}
