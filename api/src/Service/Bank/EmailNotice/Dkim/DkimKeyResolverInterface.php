<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

interface DkimKeyResolverInterface
{
    /**
     * TXT records published at `<selector>._domainkey.<domain>`.
     *
     * @return list<string>|null One string per TXT record (multi-string records already
     *                           concatenated). An empty list means the name has no TXT record
     *                           (or does not exist); null means a temporary lookup failure.
     */
    public function txtRecords(string $selector, string $domain): ?array;
}
