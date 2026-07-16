<?php
declare(strict_types=1);
namespace Allus\CompanyData\Tests;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Model\Value;
use Allus\CompanyData\Model\Change;
use PHPUnit\Framework\TestCase;

final class VerifiedTest extends TestCase
{
    private function h(string $salt, string $pt): string { return hash('sha256', $salt . $pt); }

    public function testHashMatches(): void
    {
        $salt = '0011223344556677'; $pt = 'alice@example.com';
        $this->assertTrue(Crypto::hashMatches($salt, $this->h($salt, $pt), $pt));
        $this->assertFalse(Crypto::hashMatches($salt, 'deadbeef', $pt));
        $this->assertFalse(Crypto::hashMatches('', '', $pt));
    }

    public function testValueVerified(): void
    {
        $salt = '0011223344556677'; $pt = 'alice@example.com';
        $dec = fn($w) => $pt;
        $match = Value::fromApi(['value' => $pt, 'live' => true, 'verified_hash' => $this->h($salt, $pt), 'verified_salt' => $salt], 'email', $dec);
        $this->assertTrue($match->verified);
        $mismatch = Value::fromApi(['value' => $pt, 'live' => true, 'verified_hash' => 'deadbeef', 'verified_salt' => $salt], 'email', $dec);
        $this->assertFalse($mismatch->verified);
        $absent = Value::fromApi(['value' => $pt, 'live' => true], 'email', $dec);
        $this->assertFalse($absent->verified);
    }

    public function testChangeVerified(): void
    {
        $salt = 'aabbccddeeff0011'; $pt = 'bob@example.com';
        $dec = fn($w) => $pt;
        $ch = Change::fromApi(['id' => 'c1', 'event' => 'field_updated', 'person_user_id' => 'u1', 'slug' => 'email_personal', 'value' => $pt, 'verified_hash' => $this->h($salt, $pt), 'verified_salt' => $salt], fn($s) => 'email', $dec);
        $this->assertTrue($ch->verified);
    }
}
