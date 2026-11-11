<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Per-user second-factor mode (`users.mfa_mode`).
 *
 *   default - the usual strong factors (passkey / TOTP)
 *   email   - a code mailed to the user's own address is the second factor and satisfies a
 *             required-MFA policy for that user
 *
 * Admins can never use `email`: the mailbox is a weaker factor than an authenticator and an admin
 * account can change security settings and read everything. The rule is checked on create, update,
 * role change and at login (the DB has a matching CHECK constraint as the last line of defence).
 */
final class UserMfaMode
{
    public const DEFAULT = 'default';
    public const EMAIL = 'email';

    /** Session auth methods that only an email-mode user can hold a STRONG session with. */
    public const EMAIL_SESSION_METHODS = ['email_otp', 'trusted_device', 'invite'];

    public static function isValid(mixed $mode): bool
    {
        return $mode === self::DEFAULT || $mode === self::EMAIL;
    }

    public static function isAllowedForRole(string $role, string $mode): bool
    {
        return $mode !== self::EMAIL || $role !== 'admin';
    }

    /**
     * Mode that actually applies to the user. An admin row carrying `email` (hand-edited data) is
     * treated as `default`, so the weaker factor is never honoured for an admin.
     *
     * @param array<string,mixed> $user needs `role` and `mfa_mode` (a missing mode means default)
     */
    public static function effective(array $user): string
    {
        $mode = $user['mfa_mode'] ?? self::DEFAULT;
        if ($mode !== self::EMAIL || ($user['role'] ?? '') === 'admin') {
            return self::DEFAULT;
        }
        return self::EMAIL;
    }

    /** @param array<string,mixed> $user */
    public static function usesEmail(array $user): bool
    {
        return self::effective($user) === self::EMAIL;
    }
}
