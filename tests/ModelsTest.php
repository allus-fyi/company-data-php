<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Model\Connection;
use Allus\CompanyData\Model\LogEntry;
use Allus\CompanyData\Model\RequestField;
use Allus\CompanyData\Model\Value;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;

/**
 * Output-model tests.
 *
 * Drives the model factories with hardened API JSON shaped exactly like the live
 * company-data API output (slug-keyed values; NO person source field). The
 * ciphertext fields reuse the shared decryption vector's real wrapper, decrypted
 * through the crypto core via an injected decryptValue closure.
 */
final class ModelsTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $vector;
    private static RSAPrivateKey $key;

    public static function setUpBeforeClass(): void
    {
        self::$vector = Vector::load();
        self::$key = Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], self::$vector['passphrase']);
    }

    /** @return callable(array<string,mixed>|string): string */
    private function decryptValue(): callable
    {
        return fn (array|string $w): string => Crypto::decrypt($w, self::$key);
    }

    /** @return callable(string): ?string */
    private function typeResolver(): callable
    {
        $types = ['work_email' => 'email', 'billing_address' => 'address', 'dob' => 'date', 'logo' => 'photo'];
        return fn (string $slug): ?string => $types[$slug] ?? null;
    }

    // ── RequestField definitions ─────────────────────────────────────────────

    public function testRequestFieldsParsedAndMandatoryFolded(): void
    {
        $body = ['request_fields' => [
            ['slug' => 'work_email', 'label' => 'Work email', 'type' => 'email',
             'one_time' => false, 'mandatory_provide' => true, 'mandatory_connected' => false],
            ['slug' => 'logo', 'label' => 'Logo', 'type' => 'photo',
             'one_time' => true, 'mandatory_provide' => false, 'mandatory_connected' => false],
            ['slug' => 'ref', 'label' => 'Ref', 'type' => 'text',
             'one_time' => false, 'mandatory_provide' => false, 'mandatory_connected' => true],
        ]];
        $fields = RequestField::listFromApi($body);
        self::assertSame(['work_email', 'logo', 'ref'], array_map(fn ($f) => $f->slug, $fields));
        self::assertTrue($fields[0]->mandatory);   // mandatory_provide
        self::assertFalse($fields[1]->mandatory);
        self::assertTrue($fields[1]->oneTime);
        self::assertTrue($fields[2]->mandatory);    // mandatory_connected folds in
        self::assertSame($body['request_fields'][0], $fields[0]->raw);
    }

    public function testRequestFieldCoercesXmlBoolStrings(): void
    {
        $body = ['request_fields' => [
            ['slug' => 'x', 'label' => 'X', 'type' => 'text',
             'one_time' => 'false', 'mandatory_provide' => 'true', 'mandatory_connected' => 'false'],
        ]];
        $f = RequestField::listFromApi($body)[0];
        self::assertFalse($f->oneTime);
        self::assertTrue($f->mandatory);
    }

    // ── Connection detail → typed, slug-keyed values ─────────────────────────

    public function testConnectionDetailTypedSlugKeyed(): void
    {
        $detail = [
            'connection_id' => 'csc-1',
            'user_id' => 'person-1',
            'values' => [
                'work_email' => [
                    'value' => self::$vector['text']['wrapper'],
                    'live' => true,
                    'updatedAt' => '2026-06-17T10:00:00Z',
                ],
                'billing_address' => [
                    'value' => Vector::encryptForKey(json_encode(['city' => 'Utrecht', 'country' => 'NL'], JSON_THROW_ON_ERROR)),
                    'live' => false,
                    'updatedAt' => '2026-06-16T09:00:00Z',
                ],
                'dob' => [
                    'value' => Vector::encryptForKey('1990-04-23'),
                    'live' => true,
                    'updatedAt' => '2026-06-15T08:00:00Z',
                ],
                'logo' => [
                    'value_url' => 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file',
                    'live' => true,
                    'updatedAt' => '2026-06-14T07:00:00Z',
                ],
            ],
        ];
        $identity = ['display_name' => 'Anna', 'connected_at' => '2026-06-10T00:00:00Z'];

        $conn = Connection::fromApi($detail, $this->typeResolver(), $this->decryptValue(), identity: $identity);

        self::assertSame('csc-1', $conn->id);
        self::assertSame('person-1', $conn->personId);
        self::assertSame('Anna', $conn->displayName);
        self::assertInstanceOf(\DateTimeImmutable::class, $conn->connectedAt);
        self::assertSame($detail, $conn->raw);

        $email = $conn->values['work_email'];
        self::assertInstanceOf(Value::class, $email);
        self::assertSame(self::$vector['text']['plaintext'], $email->value);
        self::assertTrue($email->live);
        self::assertInstanceOf(\DateTimeImmutable::class, $email->updatedAt);

        $addr = $conn->values['billing_address'];
        self::assertSame(['city' => 'Utrecht', 'country' => 'NL'], $addr->value);
        self::assertFalse($addr->live);

        $dob = $conn->values['dob'];
        self::assertInstanceOf(\DateTimeImmutable::class, $dob->value);
        self::assertSame('1990-04-23', $dob->value->format('Y-m-d'));

        $logo = $conn->values['logo'];
        self::assertInstanceOf(BinaryHandle::class, $logo->value);
        self::assertStringEndsWith('/slots/sf-9/file', (string) $logo->value->valueUrl());
    }

    public function testBinaryHandleLazyFetchAndDecrypt(): void
    {
        $captured = [];
        $fetch = function (string $url) use (&$captured): array {
            $captured['url'] = $url;
            return self::$vector['binary']['wrapper'];
        };
        $detail = [
            'connection_id' => 'csc-1',
            'user_id' => 'person-1',
            'values' => [
                'logo' => [
                    'value_url' => 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file',
                    'live' => true,
                    'updatedAt' => '2026-06-14T07:00:00Z',
                ],
            ],
        ];
        $conn = Connection::fromApi($detail, fn (string $s): ?string => 'photo', $this->decryptValue(), $fetch);
        $handle = $conn->values['logo']->value;
        self::assertInstanceOf(BinaryHandle::class, $handle);
        self::assertArrayNotHasKey('url', $captured); // not fetched until ->bytes()

        $data = $handle->bytes();
        self::assertStringEndsWith('/slots/sf-9/file', $captured['url']);
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $data));
        $handle->bytes(); // cached — no error / re-fetch
    }

    public function testConnectionHasNoPersonSourceField(): void
    {
        $detail = [
            'connection_id' => 'csc-1',
            'user_id' => 'person-1',
            'values' => ['work_email' => ['value' => self::$vector['text']['wrapper'], 'live' => true]],
        ];
        $conn = Connection::fromApi($detail, fn (string $s): ?string => 'email', $this->decryptValue());
        $serialized = json_encode($conn->raw, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('field_id', $serialized);
        self::assertSame(['work_email'], array_keys($conn->values));
    }

    // ── Change events ──────────────────────────────────────────────────────

    public function testChangeFieldUpdatedTypedAndIdPopulated(): void
    {
        $body = ['changes' => [
            [
                'id' => 'chg-42', 'event' => 'field_updated', 'person_user_id' => 'person-1',
                'slug' => 'work_email', 'value' => self::$vector['text']['wrapper'], 'live' => true,
                'at' => '2026-06-17T12:00:00Z',
            ],
            [
                'id' => 'chg-43', 'event' => 'connection_created', 'person_user_id' => 'person-2',
                'at' => '2026-06-17T12:05:00Z',
            ],
        ]];
        $changes = Change::listFromApi($body, fn (string $s): ?string => 'email', $this->decryptValue());

        $f = $changes[0];
        self::assertSame('chg-42', $f->id);
        self::assertSame('field_updated', $f->event);
        self::assertSame('person-1', $f->personId);
        self::assertSame('work_email', $f->slug);
        self::assertSame(self::$vector['text']['plaintext'], $f->value);
        self::assertTrue($f->live);
        self::assertInstanceOf(\DateTimeImmutable::class, $f->at);
        self::assertSame($body['changes'][0], $f->raw);

        $c = $changes[1];
        self::assertSame('chg-43', $c->id);
        self::assertSame('connection_created', $c->event);
        self::assertNull($c->slug);
        self::assertNull($c->value);
        self::assertNull($c->live);
    }

    public function testChangeFieldUpdatedBinaryIsLazyHandle(): void
    {
        $body = ['changes' => [[
            'id' => 'chg-50', 'event' => 'field_updated', 'person_user_id' => 'person-1',
            'slug' => 'logo',
            'value_url' => 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file',
            'live' => true, 'at' => '2026-06-17T12:00:00Z',
        ]]];
        $fetch = fn (string $u): array => self::$vector['binary']['wrapper'];
        $changes = Change::listFromApi($body, fn (string $s): ?string => 'photo', $this->decryptValue(), $fetch);
        $chg = $changes[0];
        self::assertInstanceOf(BinaryHandle::class, $chg->value);
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $chg->value->bytes()));
    }

    public function testChangeConsentEventHasSlugNoValue(): void
    {
        $body = ['changes' => [[
            'id' => 'chg-9', 'event' => 'consent_accepted', 'person_user_id' => 'p',
            'slug' => 'work_email', 'at' => '2026-06-17T00:00:00Z',
        ]]];
        $changes = Change::listFromApi($body, fn (string $s): ?string => 'email', fn (array|string $w): string => '');
        $chg = $changes[0];
        self::assertSame('consent_accepted', $chg->event);
        self::assertSame('work_email', $chg->slug);
        self::assertNull($chg->value); // consent events carry no value
    }

    // ── LogEntry ───────────────────────────────────────────────────────────

    public function testLogEntriesParsed(): void
    {
        $body = ['total' => 2, 'items' => [
            ['type' => 'email', 'message' => 'stale-queue alert', 'metadata' => ['days' => 3],
             'at' => '2026-06-17T06:00:00Z'],
            ['type' => 'purge', 'message' => 'purged 4 changes', 'metadata' => ['count' => 4],
             'created_at' => '2026-06-17T07:00:00Z'],
        ]];
        $logs = LogEntry::listFromApi($body);
        self::assertCount(2, $logs);
        self::assertSame('email', $logs[0]->type);
        self::assertSame(['days' => 3], $logs[0]->metadata);
        self::assertInstanceOf(\DateTimeImmutable::class, $logs[0]->at);
        // 'created_at' fallback for 'at'
        self::assertInstanceOf(\DateTimeImmutable::class, $logs[1]->at);
        self::assertSame($body['items'][1], $logs[1]->raw);
    }

    public function testChangeIncludesShareCode(): void
    {
        // Every change event carries the person's profile share_code (nullable).
        $body = ['changes' => [
            ['id' => 'chg-1', 'event' => 'connection_created',
             'person_user_id' => 'person-1', 'share_code' => 'ABC123',
             'at' => '2026-06-17T12:00:00Z'],
            ['id' => 'chg-2', 'event' => 'connection_created',
             'person_user_id' => 'person-2', 'at' => '2026-06-17T12:00:00Z'], // no share_code -> null
        ]];
        $changes = Change::listFromApi($body, fn (string $s): ?string => null, $this->decryptValue());
        self::assertSame('ABC123', $changes[0]->shareCode);
        self::assertNull($changes[1]->shareCode);
    }
}
