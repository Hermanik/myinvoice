<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Service\Auth\UserMfaMode;
use PHPUnit\Framework\TestCase;

final class UserMfaModeTest extends TestCase
{
    public function testValidModes(): void
    {
        self::assertTrue(UserMfaMode::isValid('default'));
        self::assertTrue(UserMfaMode::isValid('email'));
        self::assertFalse(UserMfaMode::isValid('sms'));
        self::assertFalse(UserMfaMode::isValid(''));
        self::assertFalse(UserMfaMode::isValid(null));
    }

    public function testAdminCanNeverUseEmailMode(): void
    {
        self::assertFalse(UserMfaMode::isAllowedForRole('admin', 'email'));
        self::assertTrue(UserMfaMode::isAllowedForRole('admin', 'default'));
        self::assertTrue(UserMfaMode::isAllowedForRole('accountant', 'email'));
        self::assertTrue(UserMfaMode::isAllowedForRole('readonly', 'email'));
    }

    public function testEffectiveModeDowngradesAnAdminRowThatCarriesEmail(): void
    {
        self::assertSame('default', UserMfaMode::effective(['role' => 'admin', 'mfa_mode' => 'email']));
        self::assertSame('email', UserMfaMode::effective(['role' => 'accountant', 'mfa_mode' => 'email']));
        self::assertSame('email', UserMfaMode::effective(['role' => 'readonly', 'mfa_mode' => 'email']));
        self::assertSame('default', UserMfaMode::effective(['role' => 'accountant', 'mfa_mode' => 'default']));
        // A row without the column (older query, bearer user) means the default mode.
        self::assertSame('default', UserMfaMode::effective(['role' => 'accountant']));
        self::assertSame('default', UserMfaMode::effective(['role' => 'accountant', 'mfa_mode' => 'garbage']));
    }

    public function testUsesEmail(): void
    {
        self::assertTrue(UserMfaMode::usesEmail(['role' => 'accountant', 'mfa_mode' => 'email']));
        self::assertFalse(UserMfaMode::usesEmail(['role' => 'admin', 'mfa_mode' => 'email']));
        self::assertFalse(UserMfaMode::usesEmail([]));
    }
}
