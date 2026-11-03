<?php

declare(strict_types=1);

namespace MyInvoice\Service\LateInterest;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\CurrencyConversionService;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use MyInvoice\Service\Validation\InvoiceValidation;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Creates the interest invoice ("vyúčtování úroku z prodlení"): a DRAFT invoice to the same client
 * that bills the interest accrued on an overdue invoice and, optionally, the fixed cost of claiming
 * the receivable. It is never issued or sent from here — the owner reviews and issues it.
 *
 * VAT: interest and the compensation of costs are not consideration for a supply, so they are
 * outside the scope of VAT (§ 2 of Act No. 235/2004 Sb.; Koordinační výbor KDP–GFŘ 349/14.12.11,
 * https://www.dauc.cz/clanky/8961/jsou-pravni-instrumenty-predmetem-dph). Every line therefore
 * carries the 0 % rate and the "outside the scope of VAT" classification ({@see InvoiceVatAdvisor::OUTSIDE_VAT_SCOPE}),
 * which the VAT books and returns skip, and the VAT advisor does not flag. This holds whether or
 * not the supplier is a VAT payer. The document says so in a note below the items.
 */
final class LateInterestInvoiceCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly CurrencyConversionService $currencies,
        private readonly VatStatusService $vatStatus,
        private readonly LateInterestService $interest,
    ) {}

    /**
     * @param array<string,mixed> $source stored overdue invoice (InvoiceRepository::find); the invoice is
     *        re-read under a row lock and all billing decisions are made from that fresh read — the caller's
     *        copy only supplies the currency (fixed once an invoice is issued) for the CNB quote
     * @return array{invoice_id: int, period_from: string, period_to: string, interest: float,
     *         fixed_cost: ?float, warnings: list<string>}
     * @throws LateInterestException when there is nothing (valid) to bill
     */
    public function create(array $source, string $asOf, bool $includeFixedCost, int $userId): array
    {
        $sourceId = (int) $source['id'];
        $quote = $this->fixedCostQuote($source, $asOf, $includeFixedCost);

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Two concurrent requests must not bill the same days: serialise per source invoice, then
            // decide on what is already billed from a fresh read (payments and claims included).
            $pdo->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([$sourceId]);
            $fresh = $this->invoices->find($sourceId) ?? throw new LateInterestException('Faktura nenalezena.');
            $plan = $this->plan($fresh, $asOf, $includeFixedCost, $quote);

            $id = $this->invoices->createDraft($plan['body'], $userId);
            $this->invoices->replaceItems($id, $plan['body']['items']);
            $this->calc->recompute($id);
            $this->recordClaim($fresh, $id, $plan, $userId);
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Backstop of the unique key (source invoice, period start) should the lock ever be bypassed.
            if ($e instanceof \PDOException && $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_lic_source_from')) {
                throw new LateInterestException('Úrok za toto období už vyúčtoval jiný požadavek. Obnovte kartu a zkuste to znovu.', 0, $e);
            }
            throw $e;
        }

        // Network call (CNB) stays outside the transaction.
        $this->rateApplier->applyToInvoice($id);

        return [
            'invoice_id'  => $id,
            'period_from' => (string) $plan['billable']['from'],
            'period_to'   => (string) $plan['billable']['to'],
            'interest'    => (float) $plan['billable']['interest'],
            'fixed_cost'  => $plan['cost_amount'],
            'warnings'    => $plan['warnings'],
        ];
    }

    /**
     * The CZK fixed cost in the invoice currency, fetched BEFORE the row lock (it can be network I/O). A foreign
     * currency is only quoted when a pre-read says the cost is claimable at all; CZK is an identity conversion.
     *
     * @param array<string,mixed> $source
     * @return array{amount: float, cross_rate: float, rate_date: string, source: string}|null
     */
    private function fixedCostQuote(array $source, string $asOf, bool $includeFixedCost): ?array
    {
        $currency = strtoupper((string) $source['currency']);
        if (!$includeFixedCost) {
            return null;
        }
        if ($currency !== 'CZK' && !($this->interest->evaluate($source, $asOf)['summary']['fixed_cost']['claimable'] ?? false)) {
            return null;
        }
        return $this->currencies->convert(
            LateInterestCalculator::FIXED_COST_CZK, 'CZK', $currency,
            new DateTimeImmutable('today'), (int) ($source['currency_decimals'] ?? 2),
        );
    }

    /**
     * Decides what to bill from the (locked, freshly read) source invoice and builds the draft.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed>|null $quote CurrencyConversionService::convert() of the CZK fixed cost
     * @return array{body: array<string,mixed>, billable: array<string,mixed>, rate: float,
     *         cost_amount: ?float, warnings: list<string>}
     * @throws LateInterestException
     */
    private function plan(array $source, string $asOf, bool $includeFixedCost, ?array $quote): array
    {
        $eval = $this->interest->evaluate($source, $asOf);
        if ($eval['reason'] !== null) {
            throw new LateInterestException((string) $eval['message']);
        }
        $s = $eval['summary'];
        if (!$s['rate_verified']) {
            throw new LateInterestException(
                'Číselník repo sazeb ČNB není aktuální pro toto období. Načtěte jej v menu Systém → Číselníky → Repo sazba ČNB.'
            );
        }
        $billable = $s['billable'];
        if ($billable['interest'] <= 0) {
            throw new LateInterestException(
                $s['billed_through'] !== null
                    ? 'Není co vyúčtovat — úrok z prodlení je vyúčtován do ' . self::czDate($s['billed_through']) . '.'
                    : 'Není co vyúčtovat — úrok z prodlení zatím nenarostl.'
            );
        }

        $lang = ($source['language'] ?? 'cs') === 'en' ? 'en' : 'cs';
        $currency = (string) $s['currency'];
        $warnings = [];

        $items = [];
        $rateId = $this->zeroRateId();
        foreach ($billable['segments'] as $seg) {
            if ($seg['interest'] <= 0) {
                continue;
            }
            $items[] = $this->item($rateId, self::segmentText($lang, $source, $seg, (float) $s['annual_rate_percent'], $currency), $seg['interest']);
        }

        $costAmount = null;
        if ($includeFixedCost && $s['fixed_cost']['claimable']) {
            if ($quote === null) {
                $warnings[] = 'Kurz ČNB pro přepočet paušální náhrady nákladů není k dispozici, náhrada nebyla do dokladu zahrnuta.';
            } else {
                $costAmount = $quote['amount'];
                $items[] = $this->item($rateId, self::fixedCostText($lang, $source, $currency, $quote), $costAmount);
            }
        }

        $body = [
            'client_id'               => (int) $source['client_id'],
            'project_id'              => $source['project_id'] ?? null,
            'invoice_type'            => 'invoice',
            'currency_id'             => (int) $source['currency_id'],
            'language'                => $lang,
            'reverse_charge'          => false,
            'prices_include_vat'      => false,
            'vat_classification_code' => InvoiceVatAdvisor::OUTSIDE_VAT_SCOPE,
            'note_above_items'        => self::noteAbove($lang, $source, $s),
            'note_below_items'        => self::noteBelow($lang),
            'items'                   => $items,
        ];
        $body = $this->defaults->resolve($body);

        $errors = InvoiceValidation::invoice(
            $body,
            $this->invoices->vatRateMap(),
            $this->invoices->vatRateCountryMap(),
            $this->vatStatus->statusForDocument((int) $source['supplier_id'], $body['tax_date'] ?? null, $body['issue_date'] ?? null),
        );
        if ($errors !== []) {
            throw new LateInterestException('Doklad s úrokem z prodlení nelze vytvořit: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        return ['body' => $body, 'billable' => $billable, 'rate' => (float) $s['annual_rate_percent'], 'cost_amount' => $costAmount, 'warnings' => $warnings];
    }

    /**
     * Links the draft to the invoice it bills and the period it covers. A cancelled interest invoice that
     * started on the same day is dropped first — its period is free again, but the unique key
     * (source invoice, period start) would otherwise reject the new claim.
     *
     * @param array<string,mixed> $source
     * @param array{billable: array<string,mixed>, rate: float, cost_amount: ?float} $plan
     */
    private function recordClaim(array $source, int $interestInvoiceId, array $plan, int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE c FROM invoice_late_interest_claims c
               JOIN invoices i ON i.id = c.invoice_id
              WHERE c.source_invoice_id = ? AND c.period_from = ? AND i.status = 'cancelled'"
        )->execute([(int) $source['id'], $plan['billable']['from']]);
        $pdo->prepare(
            'INSERT INTO invoice_late_interest_claims
                (supplier_id, source_invoice_id, invoice_id, period_from, period_to, rate_percent,
                 interest_amount, fixed_cost_amount, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $source['supplier_id'], (int) $source['id'], $interestInvoiceId, $plan['billable']['from'], $plan['billable']['to'],
            number_format($plan['rate'], 2, '.', ''),
            number_format((float) $plan['billable']['interest'], 2, '.', ''),
            $plan['cost_amount'] !== null ? number_format($plan['cost_amount'], 2, '.', '') : null,
            $userId > 0 ? $userId : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function item(int $vatRateId, string $description, float $amount): array
    {
        return [
            'description'             => $description,
            'quantity'                => 1,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => $amount,
            'vat_rate_id'             => $vatRateId,
            'vat_classification_code' => InvoiceVatAdvisor::OUTSIDE_VAT_SCOPE,
        ];
    }

    private function zeroRateId(): int
    {
        $id = $this->db->pdo()->query("SELECT id FROM vat_rates WHERE code = 'CZ-0' LIMIT 1")->fetchColumn();
        if ($id === false) {
            throw new LateInterestException('V číselníku sazeb DPH chybí nulová sazba CZ-0.');
        }
        return (int) $id;
    }

    /**
     * Description of the fixed-cost line. For a foreign-currency invoice the CZK 1,200 was converted at the
     * CNB rate of the day the draft is created (the regulation fixes the amount in CZK only); the rate used is
     * named in the text.
     *
     * @param array<string,mixed> $source
     * @param array{amount: float, cross_rate: float, rate_date: string, source: string} $quote
     */
    private static function fixedCostText(string $lang, array $source, string $currency, array $quote): string
    {
        $vs = (string) ($source['varsymbol'] ?? '');
        $text = $lang === 'en'
            ? "Fixed compensation of the costs of claiming the receivable from invoice No. {$vs} (section 3 of Government Regulation No. 351/2013 Sb.)"
            : "Paušální náhrada nákladů spojených s uplatněním pohledávky z faktury č. {$vs} (§ 3 nařízení vlády č. 351/2013 Sb.)";
        if ($quote['source'] === 'identity') {
            return $text;
        }
        $cnbRate = 1 / $quote['cross_rate']; // CZK per 1 unit of the invoice currency
        return $text . ($lang === 'en'
            ? sprintf(' — CZK 1,200 converted at the CNB rate %s CZK/%s of %s', self::num($cnbRate, 3), $currency, self::czDate($quote['rate_date']))
            : sprintf(' — 1 200 Kč přepočteno kurzem ČNB %s Kč/%s ke dni %s', self::num($cnbRate, 3), $currency, self::czDate($quote['rate_date'])));
    }

    /**
     * @param array<string,mixed> $source
     * @param array{from: string, to: string, days: int, balance: float, year_days: int, interest: float} $seg
     */
    private static function segmentText(string $lang, array $source, array $seg, float $annual, string $currency): string
    {
        $vs = (string) ($source['varsymbol'] ?? '');
        $from = self::czDate($seg['from']);
        $to = self::czDate($seg['to']);
        $bal = self::num($seg['balance']);
        $rate = self::num($annual);
        return $lang === 'en'
            ? "Late-payment interest on invoice No. {$vs}, {$from} – {$to} ({$seg['days']} days): {$bal} {$currency} × {$rate} % p.a. × {$seg['days']}/{$seg['year_days']}"
            : "Úrok z prodlení z faktury č. {$vs} za {$from} – {$to} ({$seg['days']} dní): {$bal} {$currency} × {$rate} % p.a. × {$seg['days']}/{$seg['year_days']}";
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $s summary
     */
    private static function noteAbove(string $lang, array $source, array $s): string
    {
        $vs = (string) ($source['varsymbol'] ?? '');
        $due = self::czDate((string) $s['due_date']);
        $from = self::czDate((string) $s['billable']['from']);
        $to = self::czDate((string) $s['billable']['to']);
        $annual = self::num((float) $s['annual_rate_percent']);
        $repo = self::num((float) $s['repo']['rate_percent']);
        $halfYear = self::czDate((string) $s['repo']['half_year_start']);
        return $lang === 'en'
            ? "Statement of late-payment interest on invoice No. {$vs} (due {$due}) for the period {$from} – {$to}. "
              . "Annual rate {$annual} % = CNB two-week repo rate {$repo} % valid on {$halfYear} + 8 percentage points "
              . '(section 1970 of the Civil Code, section 2 of Government Regulation No. 351/2013 Sb.).'
            : "Vyúčtování úroku z prodlení k faktuře č. {$vs} (splatnost {$due}) za období {$from} – {$to}. "
              . "Roční sazba {$annual} % = repo sazba ČNB {$repo} % platná k {$halfYear} + 8 procentních bodů "
              . '(§ 1970 občanského zákoníku, § 2 nařízení vlády č. 351/2013 Sb.).';
    }

    private static function noteBelow(string $lang): string
    {
        return $lang === 'en'
            ? 'Late-payment interest and the compensation of the costs of claiming a receivable are not consideration for a taxable supply '
              . 'and are outside the scope of VAT (section 2 of Act No. 235/2004 Sb.). This document is not a tax document.'
            : 'Úrok z prodlení a náhrada nákladů spojených s uplatněním pohledávky nejsou úplatou za zdanitelné plnění '
              . 'a nejsou předmětem daně z přidané hodnoty (§ 2 zákona č. 235/2004 Sb.). Tento doklad není daňovým dokladem.';
    }

    private static function czDate(string $ymd): string
    {
        return (new DateTimeImmutable($ymd))->format('j. n. Y');
    }

    private static function num(float $v, int $decimals = 2): string
    {
        return number_format($v, $decimals, ',', ' ');
    }
}
