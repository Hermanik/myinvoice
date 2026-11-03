<?php

declare(strict_types=1);

namespace MyInvoice\Service\LateInterest;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RepoRateRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Late-payment interest for stored invoices: decides whether the feature applies (opt-in with
 * per-client and per-invoice overrides), loads the payments and runs {@see LateInterestCalculator}.
 *
 * Opt-in precedence (the first one that is set wins):
 *   invoice.late_interest  >  client.late_interest  >  supplier.late_interest_enabled (default off)
 * where NULL on an invoice / client means "inherit".
 *
 * The result of {@see evaluate()} is what the API returns for the invoice detail; the reminder
 * e-mail gets the same structure through {@see forReminder()}.
 */
final class LateInterestService
{
    public const REASON_DISABLED          = 'disabled';
    public const REASON_INVALID_TYPE      = 'invalid_type';
    public const REASON_INVALID_STATE     = 'invalid_state';
    public const REASON_NO_AMOUNT         = 'no_amount';
    public const REASON_INTEREST_INVOICE  = 'interest_invoice';
    public const REASON_NOT_OVERDUE       = 'not_overdue';
    public const REASON_UNSUPPORTED       = 'unsupported';

    public function __construct(
        private readonly Connection $db,
        private readonly RepoRateRepository $rates,
        private readonly LateInterestCalculator $calculator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Opt-in resolution. Pure, so the precedence is trivially testable.
     *
     * @return array{enabled: bool, source: 'invoice'|'client'|'supplier'}
     */
    public static function resolveEnabled(?bool $invoiceOverride, ?bool $clientOverride, bool $supplierEnabled): array
    {
        if ($invoiceOverride !== null) {
            return ['enabled' => $invoiceOverride, 'source' => 'invoice'];
        }
        if ($clientOverride !== null) {
            return ['enabled' => $clientOverride, 'source' => 'client'];
        }
        return ['enabled' => $supplierEnabled, 'source' => 'supplier'];
    }

    /**
     * @param array<string,mixed> $invoice a stored invoice (InvoiceRepository::find)
     * @return array{
     *   enabled: bool, enabled_source: string, invoice_override: ?bool, client_override: ?bool,
     *   supplier_enabled: bool, settings: array{enabled: bool, in_reminders: bool, claim_fixed_cost: bool},
     *   reason: ?string, message: ?string, summary: ?array<string,mixed>
     * } `summary` is set only when the feature is on and the invoice is overdue.
     */
    public function evaluate(array $invoice, string $asOf): array
    {
        ['supplier' => $supplier, 'client' => $client] = $this->context((int) $invoice['supplier_id'], (int) $invoice['client_id']);
        $invoiceOverride = self::triState($invoice['late_interest'] ?? null);
        $resolved = self::resolveEnabled($invoiceOverride, $client['override'], $supplier['enabled']);
        $out = [
            'enabled'          => $resolved['enabled'],
            'enabled_source'   => $resolved['source'],
            'invoice_override' => $invoiceOverride,
            'client_override'  => $client['override'],
            'supplier_enabled' => $supplier['enabled'],
            'settings'         => $supplier,
            'reason'           => null,
            'message'          => null,
            'summary'          => null,
        ];

        $reason = match (true) {
            !$resolved['enabled']                                         => self::REASON_DISABLED,
            ($invoice['invoice_type'] ?? '') !== 'invoice'                => self::REASON_INVALID_TYPE,
            !in_array($invoice['status'] ?? '', ['issued', 'sent', 'reminded', 'paid'], true) => self::REASON_INVALID_STATE,
            (float) ($invoice['amount_to_pay'] ?? 0) <= 0                 => self::REASON_NO_AMOUNT,
            $this->isInterestInvoice((int) $invoice['id'])                => self::REASON_INTEREST_INVOICE,
            $asOf <= (string) $invoice['due_date']                        => self::REASON_NOT_OVERDUE,
            default                                                       => null,
        };
        if ($reason !== null) {
            $out['reason'] = $reason;
            $out['message'] = self::reasonMessage($reason);
            return $out;
        }

        try {
            $out['summary'] = $this->summarize($invoice, $asOf, $supplier, $client);
        } catch (LateInterestException $e) {
            $out['reason'] = self::REASON_UNSUPPORTED;
            $out['message'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * The interest block for a payment reminder, or null when the reminder must stay exactly as it
     * is without the feature: not enabled, hidden from reminders, nothing to show, or the repo-rate
     * codebook is not known to be current (an outdated rate must not reach a customer).
     *
     * Interest already billed on a separate interest invoice is NOT repeated: `interest` is the part not
     * yet invoiced, `billed_interest` / `billed_through` say what was, so the customer cannot read the
     * reminder as owing both.
     *
     * Never throws: this is an optional extra on a reminder, so any failure (a missing migration, a database
     * hiccup, a bug here) is logged and the reminder goes out unchanged.
     *
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|null
     */
    public function forReminder(array $invoice, string $today): ?array
    {
        try {
            return $this->reminderBlock($invoice, $today);
        } catch (\Throwable $e) {
            $this->logger->warning('Late-interest block skipped in a reminder', [
                'invoice_id' => $invoice['id'] ?? null,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|null
     */
    private function reminderBlock(array $invoice, string $today): ?array
    {
        // Cheap exits first: this runs for every reminder the cron sends, with the feature usually off.
        if (($invoice['invoice_type'] ?? '') !== 'invoice' || ($invoice['late_interest'] ?? null) === false) {
            return null;
        }
        $ev = $this->evaluate($invoice, $today);
        $summary = $ev['summary'];
        if ($summary === null || !$ev['settings']['in_reminders'] || !$summary['rate_verified']) {
            return null;
        }
        $unbilled = (float) $summary['billable']['interest'];
        if ($unbilled <= 0 && !$summary['fixed_cost']['claimable']) {
            return null;
        }
        return ['interest' => $unbilled] + $summary;
    }

    private static function reasonMessage(string $reason): string
    {
        return match ($reason) {
            self::REASON_DISABLED         => 'Úrok z prodlení je pro tuto fakturu vypnutý (nastavení dodavatele, klienta nebo faktury).',
            self::REASON_INVALID_TYPE     => 'Úrok z prodlení lze vyúčtovat jen u běžné faktury.',
            self::REASON_INVALID_STATE    => 'Úrok z prodlení lze vyúčtovat jen u vystavené, odeslané, upomenuté nebo zaplacené faktury.',
            self::REASON_NO_AMOUNT        => 'Faktura nemá nic k úhradě.',
            self::REASON_INTEREST_INVOICE => 'Z dokladu na úrok z prodlení se další úrok nepočítá (úrok z úroku vyžaduje ujednání, § 1806 občanského zákoníku).',
            self::REASON_NOT_OVERDUE      => 'Faktura není po splatnosti.',
            default                       => 'Úrok z prodlení nelze vypočítat.',
        };
    }

    /**
     * Non-cancelled interest invoices created for $sourceInvoiceId, oldest first.
     *
     * @return list<array{invoice_id: int, varsymbol: ?string, status: string, period_from: string,
     *         period_to: string, interest_amount: float, fixed_cost_amount: ?float}>
     */
    public function claims(int $sourceInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.invoice_id, i.varsymbol, i.status, c.period_from, c.period_to,
                    c.interest_amount, c.fixed_cost_amount
               FROM invoice_late_interest_claims c
               JOIN invoices i ON i.id = c.invoice_id
              WHERE c.source_invoice_id = ? AND i.status <> 'cancelled'
           ORDER BY c.period_from, c.id"
        );
        $stmt->execute([$sourceInvoiceId]);
        return array_map(static fn (array $r): array => [
            'invoice_id'        => (int) $r['invoice_id'],
            'varsymbol'         => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'status'            => (string) $r['status'],
            'period_from'       => (string) $r['period_from'],
            'period_to'         => (string) $r['period_to'],
            'interest_amount'   => (float) $r['interest_amount'],
            'fixed_cost_amount' => $r['fixed_cost_amount'] !== null ? (float) $r['fixed_cost_amount'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A customer is treated as a business ("podnikatel", § 3 NV 351/2013 Sb.) when the client record
     * carries a company ID or a tax ID. A private person has neither.
     *
     * @param array<string,mixed> $client
     */
    public static function isBusinessClient(array $client): bool
    {
        foreach (['ic', 'dic', 'tax_number'] as $f) {
            if (trim((string) ($client[$f] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function isInterestInvoice(int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_late_interest_claims WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array{enabled: bool, in_reminders: bool, claim_fixed_cost: bool} $supplier
     * @param array{override: ?bool, ic: ?string, dic: ?string, tax_number: ?string} $client
     * @return array<string,mixed>
     */
    private function summarize(array $invoice, string $asOf, array $supplier, array $client): array
    {
        $payments = $this->payments($invoice);
        $principal = (float) $invoice['amount_to_pay'];
        $due = (string) $invoice['due_date'];
        $repoRateOn = $this->rates->rateOn(...);

        $full = $this->calculator->calculate($principal, $due, $payments, $asOf, $repoRateOn);

        $claims = $this->claims((int) $invoice['id']);
        $billedThrough = null;
        $billedInterest = 0.0;
        $costClaimed = false;
        foreach ($claims as $c) {
            $billedThrough = $billedThrough === null || $c['period_to'] > $billedThrough ? $c['period_to'] : $billedThrough;
            $billedInterest += $c['interest_amount'];
            $costClaimed = $costClaimed || $c['fixed_cost_amount'] !== null;
        }
        $billable = $full;
        if ($billedThrough !== null) {
            $billable = $this->calculator->calculate(
                $principal, $due, $payments, $asOf, $repoRateOn,
                (new DateTimeImmutable($billedThrough))->modify('+1 day')->format('Y-m-d'),
            );
        }

        $business = self::isBusinessClient($client);

        return [
            'as_of'               => $asOf,
            'currency'            => (string) $invoice['currency'],
            'principal'           => $full['principal'],
            'due_date'            => $due,
            'delay_from'          => $full['delay_from'],
            'days'                => $full['days'],
            'interest'            => $full['interest'],
            'outstanding'         => $full['outstanding'],
            'settled_on'          => $full['settled_on'],
            'annual_rate_percent' => $full['annual_rate_percent'],
            'repo'                => $full['repo'],
            'segments'            => $full['segments'],
            'rate_verified'       => $this->rates->isCurrentFor($full['delay_from']),
            'billed_through'      => $billedThrough,
            'billed_interest'     => round($billedInterest, 2),
            'billable'            => [
                'from'     => $billable['accrual_from'],
                'to'       => $billable['accrual_to'],
                'days'     => $billable['days'],
                'interest' => $billable['interest'],
                'segments' => $billable['segments'],
            ],
            'fixed_cost'          => [
                'amount_czk'      => LateInterestCalculator::FIXED_COST_CZK,
                'business_client' => $business,
                'already_claimed' => $costClaimed,
                // Claimed only when the owner opted in, the customer is a business and it was not billed yet.
                'claimable'       => $supplier['claim_fixed_cost'] && $business && !$costClaimed,
            ],
        ];
    }

    /**
     * Payments against the invoice, oldest first. An invoice marked paid before payments were
     * recorded (legacy / import) has none; its `paid_at` then stands for one payment in full.
     *
     * @param array<string,mixed> $invoice
     * @return list<array{date: string, amount: float}>
     */
    private function payments(array $invoice): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT paid_on, amount FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_on, id');
        $stmt->execute([(int) $invoice['id']]);
        $out = array_map(
            static fn (array $r): array => ['date' => (string) $r['paid_on'], 'amount' => (float) $r['amount']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
        if ($out === [] && ($invoice['status'] ?? '') === 'paid' && !empty($invoice['paid_at'])) {
            $out[] = ['date' => (string) $invoice['paid_at'], 'amount' => (float) $invoice['amount_to_pay']];
        }
        return $out;
    }

    /**
     * The supplier's opt-in options and the client's override / identifiers, in one query.
     *
     * @return array{
     *   supplier: array{enabled: bool, in_reminders: bool, claim_fixed_cost: bool},
     *   client: array{override: ?bool, ic: ?string, dic: ?string, tax_number: ?string}
     * }
     */
    private function context(int $supplierId, int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.late_interest_enabled, s.late_interest_in_reminders, s.late_interest_claim_fixed_cost,
                    c.late_interest AS client_late_interest, c.ic, c.dic, c.tax_number
               FROM supplier s CROSS JOIN clients c
              WHERE s.id = ? AND c.id = ?'
        );
        $stmt->execute([$supplierId, $clientId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'supplier' => [
                'enabled'          => (bool) ($r['late_interest_enabled'] ?? false),
                'in_reminders'     => (bool) ($r['late_interest_in_reminders'] ?? true),
                'claim_fixed_cost' => (bool) ($r['late_interest_claim_fixed_cost'] ?? false),
            ],
            'client' => [
                'override'   => self::triState($r['client_late_interest'] ?? null),
                'ic'         => $r['ic'] ?? null,
                'dic'        => $r['dic'] ?? null,
                'tax_number' => $r['tax_number'] ?? null,
            ],
        ];
    }

    private static function triState(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }
}
