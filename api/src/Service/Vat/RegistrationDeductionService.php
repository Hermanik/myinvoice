<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Repository\VatRegistrationDeductionRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\ReportPeriod;

/**
 * Agenda "Odpočet při registraci": the items of the deduction on registration (§ 79 ZDPH) and of the reduction
 * on deregistration (§ 79a ZDPH), validated against the dated VAT status history, and their total for a DPHDP3
 * period (row 45 "odp_rez_nar", see {@see \MyInvoice\Service\Report\DphPriznaniBuilder}).
 *
 * An item belongs to an EVENT of the history: a registration (a row that switches the status to payer) or a
 * deregistration (a row that switches it from payer). The event date is the `valid_from` of that row; history
 * rows are never edited, so an item whose event no longer exists in the history is reported as orphaned and
 * stays out of every return.
 */
final class RegistrationDeductionService
{
    public const KIND_REGISTRATION = 'registration';
    public const KIND_DEREGISTRATION = 'deregistration';

    /**
     * What the audit entry of a change carries about the item. The deletion entry is the only trace of a deleted item:
     * {@see \MyInvoice\Service\Tax\ChangesAfterFilingService} rebuilds it from these fields.
     */
    public const AUDIT_FIELDS = ['id', 'kind', 'event_date', 'description', 'asset_type', 'deduction_amount'];

    public function __construct(
        private readonly VatRegistrationDeductionRepository $items,
        private readonly VatStatusService $vatStatus,
        private readonly TaxSubmissionRepository $submissions,
        private readonly DocumentLinkRepository $links,
        private readonly Connection $db,
    ) {}

    /**
     * The agenda: events of the VAT history with their items and totals, the items of events that vanished from the
     * history, and the data the UI needs (window, the period of the return, the draft reductions of a deregistration).
     * Everything is computed from one read of the history, of the items and of the filed returns.
     *
     * @return array{events: list<array<string,mixed>>, orphaned: list<array<string,mixed>>}
     */
    public function overview(int $supplierId): array
    {
        $history = $this->vatStatus->history($supplierId);
        $vatPeriod = $this->vatPeriod($supplierId);
        $all = $this->items->all($supplierId);
        $filed = [];
        foreach ($this->submissions->filedByTypes($supplierId, [TaxSubmissionRepository::FORM_DPH], withSummary: false) as $row) {
            $filed[TaxSubmissionRepository::slotKey(TaxSubmissionRepository::FORM_DPH, $row['period_year'], $row['period_month'], $row['period_quarter'])][] = $row;
        }

        $events = [];
        $known = [];
        foreach ([self::KIND_REGISTRATION => $history->registrationDates(), self::KIND_DEREGISTRATION => $history->deregistrationDates()] as $kind => $dates) {
            foreach ($dates as $date) {
                $known[$kind . '|' . $date] = true;
                $items = array_values(array_filter($all, static fn (array $i): bool => $i['kind'] === $kind && $i['event_date'] === $date));
                $events[] = $this->describeEvent($kind, $date, $vatPeriod, $items, $filed, $all);
            }
        }
        usort($events, static fn (array $a, array $b): int => [$b['date'], $a['kind']] <=> [$a['date'], $b['kind']]);

        return [
            'events'   => $events,
            'orphaned' => array_values(array_filter($all, static fn (array $i): bool => !isset($known[$i['kind'] . '|' . $i['event_date']]))),
        ];
    }

    /**
     * Total of the items for a DPHDP3 period: registration items of a registration inside the period (§ 79 odst. 7),
     * reduction items of a deregistration whose last registered day lies inside it (§ 79a odst. 5). Only items that
     * are still in the business assets count.
     *
     * `long_term_base` is the tax base of the registration deductions on long-term assets (row 47, "najednou ...
     * za první zdaňovací období registrace").
     *
     * @return array{vat: float, long_term_base: float, count: int, items: list<array<string,mixed>>, events_without_items: list<array{kind: string, date: string}>}
     */
    public function forPeriod(int $supplierId, string $start, string $end, ?VatStatusHistory $history = null): array
    {
        $history ??= $this->vatStatus->history($supplierId);
        $events = [];
        foreach ([self::KIND_REGISTRATION => $history->registrationDates(), self::KIND_DEREGISTRATION => $history->deregistrationDates()] as $kind => $dates) {
            foreach ($dates as $date) {
                if (RegistrationDeductionCalculator::eventInPeriod($kind, $date, $start, $end)) {
                    $events[] = ['kind' => $kind, 'event_date' => $date];
                }
            }
        }

        $items = $this->items->forEvents($supplierId, $events);
        $vat = 0.0;
        $longTermBase = 0.0;
        $counted = [];
        foreach ($items as $item) {
            // An asset that is no longer business property has a zero deduction / reduction.
            if ($item['deduction_amount'] <= 0) {
                continue;
            }
            $counted[] = $item;
            if ($item['kind'] === self::KIND_DEREGISTRATION) {
                $vat -= $item['deduction_amount'];
                continue;
            }
            $vat += $item['deduction_amount'];
            if ($item['asset_type'] === RegistrationDeductionCalculator::TYPE_LONG_TERM) {
                $longTermBase += $item['tax_base'];
            }
        }

        $withItems = array_map(static fn (array $i): string => $i['kind'] . '|' . $i['event_date'], $items);
        $withoutItems = [];
        foreach ($events as $event) {
            if (!in_array($event['kind'] . '|' . $event['event_date'], $withItems, true)) {
                $withoutItems[] = ['kind' => $event['kind'], 'date' => $event['event_date']];
            }
        }

        return [
            'vat'                  => round($vat, 2),
            'long_term_base'       => round($longTermBase, 2),
            'count'                => count($counted),
            'items'                => $counted,
            'events_without_items' => $withoutItems,
        ];
    }

    /**
     * Puts the result of {@see forPeriod()} into the rows of the DPH return: the total on row 45 (`odp_rez_nar`) and the
     * tax base of the long-term assets claimed on registration on row 47. A row 45 coming from the ledger (a custom
     * classification code) is dropped first — row 45 has a single source.
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines
     * @param array{vat: float, long_term_base: float, count: int} $deduction
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public static function mergeIntoLines(array $lines, array $deduction): array
    {
        unset($lines['45']);
        if ($deduction['count'] > 0) {
            $lines['45'] = [
                'base'  => 0.0,
                'vat'   => $deduction['vat'],
                'count' => $deduction['count'],
                'label' => 'Odpočet daně při registraci / snížení při zrušení registrace (§ 79, § 79a)',
            ];
        }
        if ($deduction['long_term_base'] > 0) {
            $lines['47'] ??= ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => 'Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)'];
            $lines['47']['base'] += $deduction['long_term_base'];
            $lines['47']['count'] += $deduction['count'];
        }
        return $lines;
    }

    /**
     * Warnings for the events inside a DPH period that have no items.
     *
     * @param list<array{kind: string, date: string}> $eventsWithoutItems
     * @return list<string>
     */
    public static function missingItemsWarnings(array $eventsWithoutItems): array
    {
        $warnings = [];
        foreach ($eventsWithoutItems as $event) {
            $date = (new \DateTimeImmutable($event['date']))->format('j. n. Y');
            $warnings[] = $event['kind'] === self::KIND_REGISTRATION
                ? "Dodavatel se stal plátcem DPH {$date}. Odpočet daně z majetku pořízeného před registrací (§ 79 ZDPH) jde uplatnit jen v tomto přiznání — zadejte ho v agendě Daně → Odpočet při registraci."
                : "Registrace plátce DPH končí {$date}. Odpočet daně u majetku, který zůstává v obchodním majetku, se při zrušení registrace snižuje (§ 79a ZDPH) — zkontrolujte agendu Daně → Odpočet při registraci.";
        }
        return $warnings;
    }

    /**
     * Draft reduction items (§ 79a) for the registration items still held at the deregistration: one per
     * registration item with a non-zero reduction that does not have its reduction item yet. Nothing is stored.
     *
     * @return list<array<string,mixed>>
     * @throws VatStatusException
     */
    public function suggestReversals(int $supplierId, string $deregistrationDate): array
    {
        $this->assertEvent($supplierId, self::KIND_DEREGISTRATION, $deregistrationDate);
        return self::reversalDrafts($this->items->all($supplierId), $deregistrationDate);
    }

    /**
     * @param list<array<string,mixed>> $all every item of the supplier
     * @return list<array<string,mixed>>
     */
    private static function reversalDrafts(array $all, string $deregistrationDate): array
    {
        $reversed = [];
        foreach ($all as $item) {
            if ($item['kind'] === self::KIND_DEREGISTRATION && $item['event_date'] === $deregistrationDate && $item['source_item_id'] !== null) {
                $reversed[$item['source_item_id']] = true;
            }
        }
        $drafts = [];
        foreach ($all as $item) {
            if ($item['kind'] !== self::KIND_REGISTRATION || !$item['in_business_assets'] || $item['event_date'] >= $deregistrationDate || isset($reversed[$item['id']])) {
                continue;
            }
            $amount = RegistrationDeductionCalculator::reductionAmount(
                $item['asset_type'], $item['vat_amount'], $item['acquired_on'], $deregistrationDate, $item['adjustment_years'], true,
            );
            if ($amount <= 0) {
                continue;
            }
            $drafts[] = [
                'kind'                => self::KIND_DEREGISTRATION,
                'event_date'          => $deregistrationDate,
                'description'         => $item['description'],
                'asset_type'          => $item['asset_type'],
                'acquired_on'         => $item['acquired_on'],
                'tax_base'            => $item['tax_base'],
                'vat_amount'          => $item['vat_amount'],
                'adjustment_years'    => $item['adjustment_years'],
                'in_business_assets'  => true,
                'deduction_amount'    => $amount,
                'purchase_invoice_id' => $item['purchase_invoice_id'],
                'source_item_id'      => $item['id'],
                'note'                => null,
            ];
        }
        return $drafts;
    }

    /**
     * @param array<string,mixed> $input kind, event_date, description, asset_type, acquired_on, tax_base, vat_amount,
     *   adjustment_years?, in_business_assets?, purchase_invoice_id?, source_item_id?, note?
     * @return array<string,mixed> the stored item
     * @throws VatStatusException
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $kind = (string) ($input['kind'] ?? self::KIND_REGISTRATION);
        $eventDate = trim((string) ($input['event_date'] ?? ''));
        $this->assertEvent($supplierId, $kind, $eventDate);

        $data = $this->normalizeInput($supplierId, $kind, $eventDate, $input);
        $data['kind'] = $kind;
        $data['event_date'] = $eventDate;
        $data['source_item_id'] = null;
        if ($kind === self::KIND_DEREGISTRATION && !empty($input['source_item_id'])) {
            $sourceId = (int) $input['source_item_id'];
            $source = $this->items->find($supplierId, $sourceId);
            if ($source === null || $source['kind'] !== self::KIND_REGISTRATION) {
                throw new VatStatusException('Zdrojová položka odpočtu při registraci nenalezena.', 'not_found', 404);
            }
            if ($this->items->findReversal($supplierId, $sourceId, $eventDate) !== null) {
                throw new VatStatusException('Snížení odpočtu pro tuto položku už je zadané.', 'duplicate', 409);
            }
            $data['source_item_id'] = $sourceId;
        }

        $id = $this->items->insert($supplierId, $data, $userId);
        return $this->items->find($supplierId, $id) ?? [];
    }

    /**
     * Edits an item; its kind, event and source stay.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws VatStatusException
     */
    public function update(int $supplierId, int $id, array $input): array
    {
        $existing = $this->items->find($supplierId, $id);
        if ($existing === null) {
            throw new VatStatusException('Položka nenalezena.', 'not_found', 404);
        }
        $this->assertEvent($supplierId, $existing['kind'], $existing['event_date']);
        $this->items->update($supplierId, $id, $this->normalizeInput($supplierId, $existing['kind'], $existing['event_date'], $input));
        return $this->items->find($supplierId, $id) ?? [];
    }

    /**
     * Deletes an item.
     *
     * @return array<string,mixed> the deleted item (for the audit log)
     * @throws VatStatusException
     */
    public function delete(int $supplierId, int $id): array
    {
        $item = $this->items->find($supplierId, $id);
        if ($item === null || !$this->items->delete($supplierId, $id)) {
            throw new VatStatusException('Položka nenalezena.', 'not_found', 404);
        }
        return $item;
    }

    /**
     * Validates the item fields and computes the deduction / reduction.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws VatStatusException
     */
    private function normalizeInput(int $supplierId, string $kind, string $eventDate, array $input): array
    {
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            throw new VatStatusException('Popis majetku je povinný a může mít nejvýše 255 znaků.', 'validation_failed', 422);
        }
        $type = (string) ($input['asset_type'] ?? '');
        if (!in_array($type, RegistrationDeductionCalculator::TYPES, true)) {
            throw new VatStatusException('Druh majetku musí být zásoby, dlouhodobý majetek nebo jiný majetek.', 'validation_failed', 422);
        }
        $acquiredOn = trim((string) ($input['acquired_on'] ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $acquiredOn);
        if ($parsed === false || $parsed->format('Y-m-d') !== $acquiredOn) {
            throw new VatStatusException('Datum pořízení musí být platné datum ve formátu RRRR-MM-DD.', 'validation_failed', 422);
        }
        if ($kind === self::KIND_REGISTRATION) {
            if (!RegistrationDeductionCalculator::isInWindow($acquiredOn, $eventDate)) {
                $window = RegistrationDeductionCalculator::window($eventDate);
                throw new VatStatusException(
                    sprintf(
                        'Plnění musí být uskutečněno v 12 měsících před registrací (§ 79 odst. 1 ZDPH): od %s do %s.',
                        self::czDate($window['start']),
                        self::czDate($window['end']),
                    ),
                    'outside_window',
                    422,
                );
            }
        } elseif ($acquiredOn > RegistrationDeductionCalculator::lastDayOfRegistration($eventDate)) {
            throw new VatStatusException('Majetek nemohl být pořízen po posledním dni registrace.', 'validation_failed', 422);
        }

        $taxBase = self::amount($input['tax_base'] ?? 0, 'Základ daně');
        $vat = self::amount($input['vat_amount'] ?? 0, 'Částka DPH');
        if ($vat <= 0) {
            throw new VatStatusException('Částka DPH musí být větší než nula.', 'validation_failed', 422);
        }

        $adjustmentYears = null;
        if ($type === RegistrationDeductionCalculator::TYPE_LONG_TERM) {
            $adjustmentYears = (int) ($input['adjustment_years'] ?? 5);
            if (!in_array($adjustmentYears, RegistrationDeductionCalculator::ADJUSTMENT_YEARS, true)) {
                throw new VatStatusException('Lhůta pro úpravu odpočtu musí být 5 nebo 10 let.', 'validation_failed', 422);
            }
        }

        $purchaseId = isset($input['purchase_invoice_id']) && $input['purchase_invoice_id'] !== '' && $input['purchase_invoice_id'] !== null
            ? (int) $input['purchase_invoice_id'] : null;
        if ($purchaseId !== null && !$this->links->entityBelongsToSupplier('purchase_invoice', $purchaseId, $supplierId)) {
            throw new VatStatusException('Přijatý doklad nenalezen.', 'not_found', 404);
        }
        $note = isset($input['note']) ? trim((string) $input['note']) : '';
        if (mb_strlen($note) > 500) {
            throw new VatStatusException('Poznámka může mít nejvýše 500 znaků.', 'validation_failed', 422);
        }
        $inAssets = !array_key_exists('in_business_assets', $input) || filter_var($input['in_business_assets'], FILTER_VALIDATE_BOOLEAN);

        $amount = $kind === self::KIND_REGISTRATION
            ? RegistrationDeductionCalculator::registrationAmount($type, $vat, $acquiredOn, $eventDate, $adjustmentYears, $inAssets)
            : RegistrationDeductionCalculator::reductionAmount($type, $vat, $acquiredOn, $eventDate, $adjustmentYears, $inAssets);

        return [
            'description'         => $description,
            'asset_type'          => $type,
            'acquired_on'         => $acquiredOn,
            'tax_base'            => $taxBase,
            'vat_amount'          => $vat,
            'adjustment_years'    => $adjustmentYears,
            'in_business_assets'  => $inAssets,
            'deduction_amount'    => $amount,
            'purchase_invoice_id' => $purchaseId,
            'note'                => $note === '' ? null : $note,
        ];
    }

    /**
     * The event must exist in the VAT status history.
     *
     * @throws VatStatusException
     */
    private function assertEvent(int $supplierId, string $kind, string $eventDate): void
    {
        $history = $this->vatStatus->history($supplierId);
        if ($kind === self::KIND_REGISTRATION) {
            if (!in_array($eventDate, $history->registrationDates(), true)) {
                throw new VatStatusException(
                    'V historii plátcovství DPH není registrace k tomuto datu. Nejdřív ji přidejte v Nastavení → Plátcovství DPH.',
                    'no_registration_event',
                    422,
                );
            }
            return;
        }
        if ($kind !== self::KIND_DEREGISTRATION || !in_array($eventDate, $history->deregistrationDates(), true)) {
            throw new VatStatusException(
                'V historii plátcovství DPH není zrušení registrace k tomuto datu.',
                'no_deregistration_event',
                422,
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $items the items of this event
     * @param array<string, list<array<string,mixed>>> $filed filed DPH returns by period slot (no summaries)
     * @param list<array<string,mixed>> $all every item of the supplier (for the draft reductions)
     * @return array<string,mixed>
     */
    private function describeEvent(string $kind, string $date, string $vatPeriod, array $items, array $filed, array $all): array
    {
        $isRegistration = $kind === self::KIND_REGISTRATION;
        $reference = $isRegistration ? $date : RegistrationDeductionCalculator::lastDayOfRegistration($date);
        $year = (int) substr($reference, 0, 4);
        $month = (int) substr($reference, 5, 2);
        $quarter = $vatPeriod === 'quarterly' ? (int) ceil($month / 3) : null;
        [$periodStart, $periodEnd] = ReportPeriod::range($year, $month, $vatPeriod);
        $filedReturns = $filed[TaxSubmissionRepository::slotKey(TaxSubmissionRepository::FORM_DPH, $year, $quarter === null ? $month : null, $quarter)] ?? [];
        // A not-owned asset has a zero deduction, so summing the amounts is enough.
        $total = array_sum(array_column($items, 'deduction_amount'));

        $event = [
            'kind'          => $kind,
            'date'          => $date,
            'return_period' => ['year' => $year, 'month' => $month, 'quarter' => $quarter, 'start' => $periodStart, 'end' => $periodEnd],
            'items'         => $items,
            'total'         => round($total, 2),
            'filed_return'  => $filedReturns === [] ? null : array_intersect_key(
                (array) array_last($filedReturns),
                array_flip(['id', 'submission_form', 'filing_status', 'filed_at']),
            ),
        ];
        if ($isRegistration) {
            $event['window'] = RegistrationDeductionCalculator::window($date);
        } else {
            $event['last_day'] = $reference;
            $event['suggestions'] = self::reversalDrafts($all, $date);
        }
        return $event;
    }

    private function vatPeriod(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return DphPriznaniBuilder::normalizePeriod(null, $stmt->fetchColumn() ?: null);
    }

    private static function amount(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 9_999_999_999.99) {
            throw new VatStatusException($label . ' musí být nezáporné číslo.', 'validation_failed', 422);
        }
        return round((float) $value, 2);
    }

    private static function czDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }
}
