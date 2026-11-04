<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * Looks DKIM keys up with PHP's built-in resolver (`dns_get_record`), and remembers every answer
 * (including a failure) for the lifetime of the object, i.e. one scan or one request: fifty
 * notices from the same bank cost a single lookup.
 *
 * `dns_get_record` takes no timeout argument; how long a dead resolver is waited for is governed by
 * the system resolver configuration (`options timeout:` / `attempts:` in resolv.conf).
 */
final class DnsDkimKeyResolver implements DkimKeyResolverInterface
{
    private const MAX_RECORDS = 4;
    private const MAX_RECORD_BYTES = 4096;

    /** @var array<string,list<string>|null> */
    private array $memo = [];

    /**
     * @param (\Closure(string):(array<int,array<string,mixed>>|false))|null $lookup Replaces
     *        `dns_get_record($name, DNS_TXT)`; for tests.
     */
    public function __construct(private readonly ?\Closure $lookup = null) {}

    public function txtRecords(string $selector, string $domain): ?array
    {
        $name = $selector . '._domainkey.' . $domain . '.';
        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }

        set_error_handler(static fn (): bool => true);
        try {
            $answers = $this->lookup !== null ? ($this->lookup)($name) : dns_get_record($name, DNS_TXT);
        } catch (\Throwable) {
            $answers = false;
        } finally {
            restore_error_handler();
        }

        // false = the resolver failed (timeout, SERVFAIL); a missing name or record is an empty list.
        return $this->memo[$name] = $answers === false ? null : self::recordsFromAnswers($answers);
    }

    /**
     * TXT strings from `dns_get_record()` answers; a record split into several strings is joined.
     *
     * @param array<int,array<string,mixed>> $answers
     * @return list<string>
     */
    public static function recordsFromAnswers(array $answers): array
    {
        $records = [];
        foreach ($answers as $answer) {
            if (($answer['type'] ?? null) !== 'TXT') {
                continue; // CNAME hops are followed by the resolver, only the TXT answers matter
            }
            // A long key is split into several <=255 byte strings: join them without separator.
            $txt = isset($answer['entries']) && is_array($answer['entries'])
                ? implode('', array_map('strval', $answer['entries']))
                : (string) ($answer['txt'] ?? '');
            if ($txt !== '' && strlen($txt) <= self::MAX_RECORD_BYTES) {
                $records[] = $txt;
            }
            if (count($records) >= self::MAX_RECORDS) {
                break;
            }
        }
        return $records;
    }
}
