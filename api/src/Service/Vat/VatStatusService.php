<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierVatStatusRepository;
use MyInvoice\Service\Report\ReportPeriod;

/**
 * Rules around the supplier's dated VAT status history.
 *
 * Reads answer "what was the status on date D" (the only question date-based code
 * should ask). Writes keep the legacy `supplier.is_vat_payer` / `is_identified`
 * columns in sync as a CACHE of "status today" — they are recomputed after every
 * history change and daily by cron-refresh-vat-status.
 */
final class VatStatusService
{
    /** Value of a flat tax band that means "not in the flat tax regime". */
    private const NO_FLAT_TAX = 'none';

    public function __construct(private readonly SupplierVatStatusRepository $repo) {}

    /** For classes that only hold a Connection (and are built by hand in places). */
    public static function forConnection(Connection $db): self
    {
        return new self(new SupplierVatStatusRepository($db));
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Date that decides the VAT status of a document: DUZP, falling back to the issue
     * date, falling back to today (a brand new draft without any date).
     */
    public static function documentDate(?string $taxDate, ?string $issueDate): string
    {
        foreach ([$taxDate, $issueDate] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return VatStatusHistory::day($candidate);
            }
        }
        return self::today();
    }

    /** {@see documentDate()} of a stored invoice / purchase invoice row. */
    public static function documentDateOf(array $doc): string
    {
        return self::documentDate($doc['tax_date'] ?? null, $doc['issue_date'] ?? null);
    }

    // ───────────────────────────── reads ─────────────────────────────

    public function history(int $supplierId): VatStatusHistory
    {
        return $this->repo->history($supplierId);
    }

    public function statusAt(int $supplierId, string $date): string
    {
        return $this->history($supplierId)->statusAt($date);
    }

    public function isPayerAt(int $supplierId, string $date): bool
    {
        return $this->history($supplierId)->isPayerAt($date);
    }

    /** @return array{is_vat_payer: bool, is_identified: bool} */
    public function flagsAt(int $supplierId, string $date): array
    {
        return $this->history($supplierId)->flagsAt($date);
    }

    /**
     * Status flags of the supplier on a document's DUZP — what an invoice without (or with a
     * legacy) snapshot must show instead of the cached "status today" columns.
     *
     * @param array<string,mixed> $doc row with tax_date / issue_date
     * @return array{is_vat_payer: bool, is_identified: bool}
     */
    public function flagsForDocument(int $supplierId, array $doc): array
    {
        return $this->flagsAt($supplierId, self::documentDateOf($doc));
    }

    /** Status for a document identified by its DUZP / issue date. */
    public function statusForDocument(int $supplierId, ?string $taxDate, ?string $issueDate): string
    {
        return $this->statusAt($supplierId, self::documentDate($taxDate, $issueDate));
    }

    /**
     * Everything the settings UI and API clients need about the history.
     *
     * @return array{
     *   status: string, is_vat_payer: bool, is_identified: bool, as_of: string,
     *   history: list<array<string,mixed>>,
     *   next_change: array{valid_from: string, status: string}|null,
     *   flat_tax_conflict_year: int|null
     * }
     */
    public function overview(int $supplierId, ?string $today = null): array
    {
        $today ??= self::today();
        [$rows, $history] = $this->load($supplierId);
        $status = $history->statusAt($today);

        // The row in effect today = the last stored row with valid_from <= today.
        $currentId = null;
        foreach ($rows as $row) {
            if ($row['valid_from'] <= $today) {
                $currentId = $row['id'];
            }
        }
        $earliestId = $rows[0]['id'] ?? null;
        // Registration / deregistration rows are the events of the agenda "Odpočet při registraci" (§ 79, § 79a ZDPH).
        $registrations = $history->registrationDates();
        $deregistrations = $history->deregistrationDates();
        $list = array_map(static fn (array $r): array => [
            'id'         => $r['id'],
            'valid_from' => $r['valid_from'],
            'status'     => $r['status'],
            'note'       => $r['note'],
            'created_at' => $r['created_at'],
            'created_by' => $r['created_by'],
            'is_future'  => $r['valid_from'] > $today,
            'is_current' => $r['id'] === $currentId,
            'can_delete' => $r['id'] !== $earliestId,
            // The event of the agenda "Odpočet při registraci" this row is, if any.
            'deduction_event' => in_array($r['valid_from'], $registrations, true) ? 'registration'
                : (in_array($r['valid_from'], $deregistrations, true) ? 'deregistration' : null),
        ], $rows);

        $conflicts = $this->flatTaxConflictYears($supplierId, $history, null, (int) substr($today, 0, 4));

        return [
            'status'                 => $status,
            ...VatStatus::toFlags($status),
            'as_of'                  => $today,
            'history'                => $list,
            'next_change'            => $history->nextChangeAfter($today),
            'flat_tax_conflict_year' => $conflicts[0] ?? null,
        ];
    }

    /**
     * Filing guard for VAT returns / control statements / recapitulative statements: an XML
     * for the period may only be produced when the supplier was a payer or an identified
     * person on at least one day of it. Which documents count is decided per document
     * (see VatLedgerService); this only refuses periods that are entirely out of scope.
     *
     * @throws VatStatusException
     */
    public function assertVatDutiesInPeriod(int $supplierId, string $start, string $end): void
    {
        if (!$this->history($supplierId)->hasVatDutiesBetween($start, $end)) {
            throw new VatStatusException(
                'V zvoleném období nebyl dodavatel plátcem DPH ani identifikovanou osobou — výkaz nelze vygenerovat. '
                . 'Plátcovství DPH se nastavuje v Nastavení → Plátcovství DPH.',
                'not_vat_payer_in_period',
                422,
            );
        }
    }

    /** {@see assertVatDutiesInPeriod()} for a VAT filing period (month, or the quarter containing $month). */
    public function assertVatDutiesInReportPeriod(int $supplierId, int $year, int $month, string $period): void
    {
        [$start, $end] = ReportPeriod::range($year, $month, $period);
        $this->assertVatDutiesInPeriod($supplierId, $start, $end);
    }

    // ───────────────────────────── writes ─────────────────────────────

    /**
     * Adds a history row valid from $validFrom and refreshes the cache.
     *
     * @return array<string,mixed> the stored row
     * @throws VatStatusException
     */
    public function add(int $supplierId, string $validFrom, string $status, ?string $note, ?int $userId): array
    {
        $validFrom = trim($validFrom);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $validFrom);
        if ($d === false || $d->format('Y-m-d') !== $validFrom) {
            throw new VatStatusException('Datum „platí od“ musí být platné datum ve formátu RRRR-MM-DD.', 'validation_failed', 400);
        }
        if ($validFrom < VatStatus::EARLIEST_DATE || $validFrom > '2099-12-31') {
            throw new VatStatusException('Datum „platí od“ musí být v rozmezí 1. 1. 2000 až 31. 12. 2099.', 'validation_failed', 422);
        }
        if (!VatStatus::isValid($status)) {
            throw new VatStatusException("Stav musí být 'payer', 'non_payer' nebo 'identified'.", 'validation_failed', 400);
        }
        $note = $note !== null ? trim($note) : null;
        $note = ($note === null || $note === '') ? null : $note;
        if ($note !== null && mb_strlen($note) > 255) {
            throw new VatStatusException('Poznámka může mít nejvýše 255 znaků.', 'validation_failed', 400);
        }

        $this->requireHistoryTable();
        // One transaction: a refused row (duplicate / flat tax rule) must not leave the floor row seeded either.
        $rowId = $this->repo->transactional(function () use ($supplierId, $validFrom, $status, $note, $userId): int {
            $this->ensureFloorRow($supplierId);
            [$rows, $before] = $this->load($supplierId);
            foreach ($rows as $row) {
                if ($row['valid_from'] === $validFrom) {
                    throw new VatStatusException(
                        'Pro toto datum už záznam existuje. Smažte ho a vytvořte znovu.',
                        'vat_status_duplicate',
                        409,
                    );
                }
            }
            $after = new VatStatusHistory([...$before->rows(), ['valid_from' => $validFrom, 'status' => $status]]);
            $this->assertNoNewFlatTaxConflict($supplierId, $before, $after);

            $id = $this->repo->insert($supplierId, $validFrom, $status, $note, $userId);
            $this->refreshCache($supplierId);
            return $id;
        });

        return $this->repo->find($supplierId, $rowId) ?? [];
    }

    /**
     * Deletes a history row and refreshes the cache. The earliest row is the floor of
     * the history and cannot be deleted.
     *
     * @throws VatStatusException
     */
    public function delete(int $supplierId, int $rowId): void
    {
        $this->requireHistoryTable();
        [$rows, $before] = $this->load($supplierId);
        if (!in_array($rowId, array_column($rows, 'id'), true)) {
            throw new VatStatusException('Záznam nenalezen.', 'not_found', 404);
        }
        if ($rows[0]['id'] === $rowId) {
            throw new VatStatusException(
                'Nejstarší záznam nelze smazat — historie musí vždy začínat nějakým stavem.',
                'vat_status_earliest',
                422,
            );
        }

        $after = new VatStatusHistory(array_filter($rows, static fn (array $r): bool => $r['id'] !== $rowId));
        $this->assertNoNewFlatTaxConflict($supplierId, $before, $after);

        $this->repo->transactional(function () use ($supplierId, $rowId): void {
            $this->repo->delete($supplierId, $rowId);
            $this->refreshCache($supplierId);
        });
    }

    /**
     * Decides what a legacy `is_vat_payer` / `is_identified` change (old API clients)
     * means for the history: a NEW row valid from today, never a rewrite of the past.
     * Pure validation + planning, no writes.
     *
     * @param ?bool   $isVatPayer     value sent by the client (null = not sent)
     * @param ?bool   $isIdentified   value sent by the client (null = not sent)
     * @param ?string $newFlatTaxBand flat tax band the same request sets, if any
     * @return string|null the status to be valid from today, null = nothing changes
     * @throws VatStatusException
     */
    public function planFlagChange(int $supplierId, ?bool $isVatPayer, ?bool $isIdentified, ?string $newFlatTaxBand = null, ?string $today = null): ?string
    {
        $today ??= self::today();
        $history = $this->history($supplierId);
        $current = $history->statusAt($today);

        $payer = $isVatPayer ?? VatStatus::toFlags($current)['is_vat_payer'];
        $identified = $isIdentified ?? VatStatus::toFlags($current)['is_identified'];
        if ($payer && $identified) {
            throw new VatStatusException(
                'Identifikovaná osoba je z definice neplátce DPH — nelze kombinovat s plátcovstvím. Plátce DPH přepínač identifikované osoby vypne.',
            );
        }
        $desired = VatStatus::fromFlags($payer, $identified);
        if ($desired === $current) {
            if ($newFlatTaxBand !== null) {
                $this->assertFlatTaxAllowed($supplierId, $newFlatTaxBand, $today);
            }
            return null;
        }

        $this->requireHistoryTable();
        $after = $this->historyWith($history, $today, $desired);
        $this->assertNoNewFlatTaxConflict($supplierId, $history, $after, $newFlatTaxBand, (int) substr($today, 0, 4));
        return $desired;
    }

    /** {@see planFlagChange()} followed by {@see commitFlagChange()}. */
    public function applyFlagChange(int $supplierId, ?bool $isVatPayer, ?bool $isIdentified, ?int $userId, ?string $newFlatTaxBand = null, ?string $today = null): ?string
    {
        $today ??= self::today();
        $desired = $this->planFlagChange($supplierId, $isVatPayer, $isIdentified, $newFlatTaxBand, $today);
        if ($desired !== null) {
            $this->commitFlagChange($supplierId, $desired, $userId, $today);
        }
        return $desired;
    }

    /**
     * Writes a status planned by {@see planFlagChange()}: inserts a row valid from today (or
     * flips the status of today's own row, as a second change on the same day cannot add another
     * row for the same date) and refreshes the cache.
     */
    public function commitFlagChange(int $supplierId, string $desired, ?int $userId, ?string $today = null): void
    {
        $today ??= self::today();
        $this->repo->transactional(function () use ($supplierId, $today, $desired, $userId): void {
            $this->ensureFloorRow($supplierId);
            $rows = $this->repo->rows($supplierId);
            $todayRow = null;
            foreach ($rows as $row) {
                if ($row['valid_from'] === $today) {
                    $todayRow = $row;
                }
            }
            if ($todayRow === null) {
                $this->repo->insert($supplierId, $today, $desired, 'Změna z nastavení dodavatele', $userId);
            } else {
                // Flip today's row, or drop it when the flip merely restores the status that held
                // before it (a second change on the same day undoing the first).
                $before = (new VatStatusHistory(array_filter($rows, static fn (array $r): bool => $r['id'] !== $todayRow['id'])))->statusAt($today);
                if ($before === $desired && $rows[0]['id'] !== $todayRow['id']) {
                    $this->repo->delete($supplierId, $todayRow['id']);
                } else {
                    $this->repo->updateStatus($supplierId, $todayRow['id'], $desired);
                }
            }
            $this->refreshCache($supplierId, $today);
        });
    }

    /**
     * A flat tax band may be set only when the supplier is not a VAT payer on any day
     * of the year the band applies to (current year for the supplier-level band).
     *
     * @throws VatStatusException
     */
    public function assertFlatTaxAllowed(int $supplierId, string $band, ?string $today = null): void
    {
        if ($band === self::NO_FLAT_TAX) {
            return;
        }
        $currentYear = (int) substr($today ?? self::today(), 0, 4);
        $year = $this->flatTaxConflictYears($supplierId, $this->history($supplierId), $band, $currentYear)[0] ?? null;
        if ($year !== null) {
            throw $this->flatTaxException($year);
        }
    }

    /**
     * Seeds the first history row of a freshly created supplier from its flags
     * (no-op when the supplier already has history or the table is not migrated).
     */
    public function seedInitialRow(int $supplierId, bool $isVatPayer, bool $isIdentified): void
    {
        if (!$this->repo->hasTable() || $this->repo->hasRows($supplierId)) {
            return;
        }
        $this->repo->insert(
            $supplierId,
            VatStatus::EARLIEST_DATE,
            VatStatus::fromFlags($isVatPayer, $isIdentified),
            'Počáteční stav',
            null,
        );
    }

    /** Drops all history rows of a supplier that is being deleted (that path runs without FK cascades). */
    public function deleteAllForSupplier(int $supplierId): void
    {
        $this->repo->deleteForSupplier($supplierId);
    }

    // ───────────────────────────── cache ─────────────────────────────

    /**
     * Recomputes `supplier.is_vat_payer` / `is_identified` from the history for $today.
     *
     * @return bool true when the cache changed
     */
    public function refreshCache(int $supplierId, ?string $today = null): bool
    {
        $cached = $this->repo->cachedFlags($supplierId);
        if ($cached === null) {
            return false;
        }
        $target = $this->history($supplierId)->flagsAt($today ?? self::today());
        if ($cached === $target) {
            return false;
        }
        $this->repo->writeCache($supplierId, $target['is_vat_payer'], $target['is_identified']);
        return true;
    }

    /**
     * Recomputes the cache of every supplier (daily cron).
     *
     * @return array{checked: int, changed: int, changed_supplier_ids: list<int>}
     */
    public function refreshAllCaches(?string $today = null): array
    {
        $ids = $this->repo->supplierIds();
        $changed = [];
        foreach ($ids as $id) {
            if ($this->refreshCache($id, $today)) {
                $changed[] = $id;
            }
        }
        return ['checked' => count($ids), 'changed' => count($changed), 'changed_supplier_ids' => $changed];
    }

    // ─────────────────────────── flat tax rule ───────────────────────────

    /**
     * Years in which the supplier is a VAT payer for at least one day although a flat
     * tax band applies to that year, ascending.
     *
     * The band of a year is the saved tax profile of that year; the supplier-level band
     * (`supplier.flat_tax_band`) is only the default of the CURRENT year, so a payer row
     * in a future year does not clash with today's setup. $bandOverride replaces the
     * current-year band (a settings change that is being validated).
     *
     * @return list<int>
     */
    public function flatTaxConflictYears(int $supplierId, VatStatusHistory $history, ?string $bandOverride, int $currentYear): array
    {
        $bands = $this->repo->profileFlatTaxBands($supplierId);
        if ($bandOverride !== null) {
            $bands[$currentYear] = $bandOverride;
        } elseif (!isset($bands[$currentYear])) {
            $bands[$currentYear] = $this->repo->supplierFlatTaxBand($supplierId);
        }
        ksort($bands);

        $years = [];
        foreach ($bands as $year => $band) {
            if ($band !== self::NO_FLAT_TAX && $history->hasPayerTimeBetween("$year-01-01", "$year-12-31")) {
                $years[] = (int) $year;
            }
        }
        return $years;
    }

    /** Rejects a history change that creates a payer/flat-tax overlap which did not exist before. */
    private function assertNoNewFlatTaxConflict(
        int $supplierId,
        VatStatusHistory $before,
        VatStatusHistory $after,
        ?string $bandOverride = null,
        ?int $currentYear = null,
    ): void {
        $currentYear ??= (int) date('Y');
        $existing = $this->flatTaxConflictYears($supplierId, $before, $bandOverride, $currentYear);
        foreach ($this->flatTaxConflictYears($supplierId, $after, $bandOverride, $currentYear) as $year) {
            if (!in_array($year, $existing, true)) {
                throw $this->flatTaxException($year);
            }
        }
    }

    private function flatTaxException(int $year): VatStatusException
    {
        return new VatStatusException(
            "Pro rok {$year} je nastavená paušální daň, ale dodavatel je v něm plátcem DPH. "
            . 'Plátce DPH nemůže být v režimu paušální daně (§ 7a ZDP) — nejdřív pro tento rok paušální daň vypněte.',
            'vat_flat_tax_conflict',
            422,
        );
    }

    /**
     * A supplier without stored rows (created by raw SQL, or migrated before it had any) derives its
     * history from the cached flags. Before the first real row is written, materialise that floor
     * row — otherwise a lone future "payer" row would become the status of every earlier date.
     */
    private function ensureFloorRow(int $supplierId): void
    {
        $flags = $this->repo->hasRows($supplierId) ? null : $this->repo->cachedFlags($supplierId);
        if ($flags !== null) {
            $this->seedInitialRow($supplierId, $flags['is_vat_payer'], $flags['is_identified']);
        }
    }

    /** @throws VatStatusException when migration 9002 has not run (writes would fail with a 500) */
    private function requireHistoryTable(): void
    {
        if (!$this->repo->hasTable()) {
            throw new VatStatusException(
                'Historie plátcovství DPH není dostupná — spusťte databázové migrace (php api/bin/migrate.php).',
                'migration_required',
                409,
            );
        }
    }

    /**
     * Stored rows together with the history built from them (one query); a supplier without
     * stored rows gets the history derived from its cached flags.
     *
     * @return array{0: list<array<string,mixed>>, 1: VatStatusHistory}
     */
    private function load(int $supplierId): array
    {
        $rows = $this->repo->rows($supplierId);
        return [$rows, $rows !== [] ? new VatStatusHistory($rows) : $this->repo->history($supplierId)];
    }

    /** History with a row for $date set to $status (replacing an existing row of that date). */
    private function historyWith(VatStatusHistory $history, string $date, string $status): VatStatusHistory
    {
        $rows = array_values(array_filter($history->rows(), static fn (array $r): bool => $r['valid_from'] !== $date));
        $rows[] = ['valid_from' => $date, 'status' => $status];
        return new VatStatusHistory($rows);
    }
}
