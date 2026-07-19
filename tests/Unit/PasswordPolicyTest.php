<?php
declare(strict_types=1);

namespace Spoome\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spoome\Domain\Auth\AuthService;

final class PasswordPolicyTest extends TestCase
{
    public function testRejectsTooShort(): void
    {
        $this->assertFalse(AuthService::isStrongPassword('Ab1'));
        $this->assertFalse(AuthService::isStrongPassword('Abcdefgh1')); // 9 char
    }

    public function testRequiresLetterAndDigit(): void
    {
        $this->assertFalse(AuthService::isStrongPassword('abcdefghij'));   // no digit
        $this->assertFalse(AuthService::isStrongPassword('1234567890'));   // no letter
    }

    public function testAcceptsStrong(): void
    {
        $this->assertTrue(AuthService::isStrongPassword('SpoomeBeta25!'));
        $this->assertTrue(AuthService::isStrongPassword('abcdefghi1'));    // 10 char, letter+digit
    }

    public function testRejectsOver72Bytes(): void
    {
        $this->assertFalse(AuthService::isStrongPassword(str_repeat('a', 72) . '1')); // 73 byte
    }
}
