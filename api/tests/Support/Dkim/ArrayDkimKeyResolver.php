<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimKeyResolverInterface;

/**
 * In-memory DKIM key "DNS" for tests: no network involved. Counts lookups.
 */
final class ArrayDkimKeyResolver implements DkimKeyResolverInterface
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param array<string,list<string>|null> $zone `<selector>._domainkey.<domain>` => TXT records
     *        (null = temporary DNS failure). Names that are not listed do not exist.
     */
    public function __construct(public array $zone = []) {}

    public function txtRecords(string $selector, string $domain): ?array
    {
        $name = $selector . '._domainkey.' . $domain;
        $this->lookups[] = $name;
        return array_key_exists($name, $this->zone) ? $this->zone[$name] : [];
    }
}
