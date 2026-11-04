<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * Domain-name helpers of the DKIM check: validation and relaxed alignment between a signing
 * (or authenticated) domain and the From domain.
 *
 * Domains align when they are equal or one is a subdomain of the other
 * (`airbank.cz` ~ `mail.airbank.cz`). There is no public-suffix list involved: sibling
 * subdomains (`a.airbank.cz` vs `b.airbank.cz`) are deliberately NOT aligned, which is
 * stricter than DMARC but never accepts an unrelated domain. A bare single label
 * (`com`, `cz`) never aligns with anything but itself.
 */
final class DomainAlignment
{
    private const NAME_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Lower-case host name of at least two LDH labels, at most 253 characters (what a signing
     * or From domain has to look like; also keeps hostile values out of DNS queries).
     */
    public static function isValidName(string $name): bool
    {
        return strlen($name) <= 253 && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function aligns(string $domain, string $other): bool
    {
        $domain = strtolower(trim($domain, ". \t"));
        $other = strtolower(trim($other, ". \t"));
        if ($domain === '' || $other === '') {
            return false;
        }
        if ($domain === $other) {
            return true;
        }
        // The shorter side is the parent; a single label is a TLD, never a parent we trust.
        [$parent, $child] = strlen($domain) < strlen($other) ? [$domain, $other] : [$other, $domain];
        return str_contains($parent, '.') && str_ends_with($child, '.' . $parent);
    }
}
