<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Service\Tax\VatPaymentService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * "Platba DPH": the card of a DPH return (amount, due date, office VAT account, variable symbol, QR),
 * the refund case, the manual paid flag and the recognition of the payment on a bank transaction.
 * Works in the year 2099 only; every created row is removed again.
 */
#[Group('integration')]
final class VatPaymentTest extends TestCase
{
    private const DIC = 'CZ12345678';

    private ?FilingFixture $fx = null;
    private VatPaymentService $payments;
    private TaxSubmissionFilingService $filing;
    private TaxSubmissionRepository $repo;
    private StatementMatcher $matcher;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->payments = $this->fx->container->get(VatPaymentService::class);
        $this->filing = $this->fx->container->get(TaxSubmissionFilingService::class);
        $this->repo = $this->fx->container->get(TaxSubmissionRepository::class);
        $this->matcher = $this->fx->container->get(StatementMatcher::class);
        $this->fx->configureSupplier(['dic' => self::DIC, 'financial_office_code' => '451']);
        $this->fx->payer();
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    private function sid(): int
    {
        return $this->fx->supplierId;
    }

    /** A filed DPH return of 2099-$month with the given tax; returns its id. */
    private function filedReturn(int $month, int $tax, string $form = 'B', ?int $quarter = null): int
    {
        $id = $this->fx->dphSubmission(2099, $quarter === null ? $month : null, $quarter, $tax, $form);
        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);
        return $id;
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function offices(): array
    {
        return [
            'Praha'      => ['451', '705-77628031/0710', 'CZ74071000070500776280' . '31', 'Finanční úřad pro hlavní město Prahu'],
            'Vysočina'   => ['460', '705-67626681/0710', 'CZ67071000070500676266' . '81', 'Finanční úřad pro Kraj Vysočina'],
            'Zlín'       => ['464', '705-47620661/0710', 'CZ65071000070500476206' . '61', 'Finanční úřad pro Zlínský kraj'],
            'SFÚ'        => ['13',  '705-77620021/0710', 'CZ34071000070500776200' . '21', 'Specializovaný finanční úřad'],
        ];
    }

    #[DataProvider('offices')]
    public function testCardCarriesAmountDueDateAccountVariableSymbolAndQr(string $code, string $display, string $iban, string $office): void
    {
        $this->fx->configureSupplier(['financial_office_code' => $code]);
        $id = $this->filedReturn(5, 25_000);

        $card = $this->payments->card($this->repo->findMeta($id, $this->sid()), withQr: true);

        self::assertSame('payment', $card['kind']);
        self::assertSame(25_000, $card['amount']);
        self::assertSame(25_000, $card['amount_due']);
        self::assertSame('2099-06-25', $card['due_date'], 'the 25th of the month after the period');
        self::assertSame($display, $card['account']['display']);
        self::assertSame($iban, $card['account']['iban']);
        self::assertSame(['code' => ltrim($code, '0'), 'name' => $office], $card['office']);
        self::assertSame('12345678', $card['variable_symbol']);
        self::assertSame('unpaid', $card['state']);
        self::assertTrue($card['payable']);
        self::assertSame([], $card['warnings']);
        self::assertStringStartsWith('data:image/png;base64,', (string) $card['qr']);
    }

    public function testQrIsBuiltFromTheOfficeAccountAndTheVariableSymbolWithoutTheDueDate(): void
    {
        $captured = [];
        $qr = $this->createStub(\MyInvoice\Service\Qr\QrPaymentGenerator::class);
        $qr->method('generate')->willReturnCallback(function (...$args) use (&$captured): string {
            $captured[] = $args;
            return 'data:image/png;base64,QR';
        });
        $service = new VatPaymentService($this->repo, $this->fx->db, $qr, $this->fx->container->get(\MyInvoice\Service\ActivityLogger::class));
        $id = $this->fx->dphSubmission(2099, 5, null, 25_000);   // due 2099-06-25

        $service->card($this->repo->findMeta($id, $this->sid()), withQr: true);

        [$currency, $amount, $vs, $bank, , $dueDate, $message] = $captured[0];
        self::assertSame(['CZK', 25_000.0, '12345678'], [$currency, $amount, $vs]);
        self::assertSame(['account_number' => '705-77628031', 'bank_code' => '0710', 'iban' => 'CZ7407100007050077628031'], $bank);
        self::assertSame('DPH 05/2099', $message);
        // A tax counts as paid when it is credited, so the QR must not postpone the payment to the due date:
        // no date is passed and the generator dates the payment today.
        self::assertNull($dueDate);
    }

    public function testQuarterlyAndWeekendDueDates(): void
    {
        $quarterly = $this->payments->card($this->repo->findMeta($this->filedReturn(6, 1000, 'B', 2), $this->sid()));
        self::assertSame('2099-07-27', $quarterly['due_date'], 'Q2: 25. 7. 2099 is a Saturday');
        self::assertSame('2099 Q2', $quarterly['period_label']);

        $weekend = $this->payments->card($this->repo->findMeta($this->filedReturn(3, 1000), $this->sid()));
        self::assertSame('2099-04-27', $weekend['due_date'], '25. 4. 2099 is a Saturday');
    }

    public function testExcessDeductionShowsTheRefundInsteadOfAPayment(): void
    {
        $id = $this->filedReturn(4, -4_200);

        $card = $this->payments->card($this->repo->findMeta($id, $this->sid()), withQr: true);

        self::assertSame('refund', $card['kind']);
        self::assertSame(4_200, $card['amount']);
        self::assertSame('refund', $card['state']);
        self::assertFalse($card['payable']);
        self::assertNull($card['qr'], 'there is nothing to pay');
        $this->assertPaymentError('not_payable', fn () => $this->payments->markPaid($this->sid(), $id, null, null, null));
        self::assertSame([], $this->payments->unpaidLiabilities($this->sid()));
    }

    public function testMissingOrUnknownOfficeAndMissingDicAreReportedNotGuessed(): void
    {
        $id = $this->filedReturn(5, 5_000);
        $row = fn () => $this->repo->findMeta($id, $this->sid());

        $this->fx->configureSupplier(['financial_office_code' => null]);
        $card = $this->payments->card($row(), withQr: true);
        self::assertNull($card['account']);
        self::assertNull($card['qr']);
        self::assertSame('office_unknown', $card['warnings'][0]['code']);

        $this->fx->configureSupplier(['financial_office_code' => '2001']); // a workplace code is not an office code
        self::assertSame('office_unknown', $this->payments->card($row())['warnings'][0]['code']);

        $this->fx->configureSupplier(['financial_office_code' => '451', 'dic' => null]);
        $card = $this->payments->card($row(), withQr: true);
        self::assertSame(['dic_missing'], array_column($card['warnings'], 'code'));
        self::assertNull($card['variable_symbol']);
        self::assertNull($card['qr']);
    }

    public function testManualPaidFlagLifecycle(): void
    {
        $id = $this->filedReturn(5, 8_000);
        $notFiled = $this->fx->dphSubmission(2099, 8, null, 1_000);
        $this->assertPaymentError('not_payable', fn () => $this->payments->markPaid($this->sid(), $notFiled, null, null, null), 'a return that is not filed yet');
        self::assertSame('not_filed', $this->payments->card($this->repo->findMeta($notFiled, $this->sid()))['state']);

        $paid = $this->payments->markPaid($this->sid(), $id, '2020-06-20', null, $this->fx->userId);
        self::assertSame('paid', $paid['state']);
        self::assertSame(['at' => '2020-06-20', 'amount' => 8000.0, 'source' => 'manual', 'bank_transaction_id' => null], $paid['paid']);
        $this->assertPaymentError('already_paid', fn () => $this->payments->markPaid($this->sid(), $id, null, null, null));
        $this->assertPaymentError('not_paid', fn () => $this->payments->clearPaid($this->sid(), $notFiled, null), 'nothing to clear');

        $reopened = $this->payments->clearPaid($this->sid(), $id, $this->fx->userId);
        self::assertSame('unpaid', $reopened['state']);
        self::assertNull($reopened['paid']);
        $this->assertPaymentError('validation_failed', fn () => $this->payments->markPaid($this->sid(), $id, '31.12.2020', null, null));
    }

    public function testPaymentToACorrectiveReturnOnlyAsksForTheRest(): void
    {
        $original = $this->filedReturn(5, 10_000, 'B');
        $this->payments->markPaid($this->sid(), $original, '2020-06-20', null, null);
        $corrective = $this->filedReturn(5, 12_000, 'O');

        $originalCard = $this->payments->card($this->repo->findMeta($original, $this->sid()));
        $correctiveCard = $this->payments->card($this->repo->findMeta($corrective, $this->sid()));

        self::assertSame('superseded', $originalCard['state'], 'the corrective return replaces the original');
        self::assertSame(12_000, $correctiveCard['amount']);
        self::assertSame(2_000, $correctiveCard['amount_due'], '12 000 filed, 10 000 already paid');
        self::assertSame('unpaid', $correctiveCard['state']);
        $open = $this->payments->unpaidLiabilities($this->sid());
        self::assertSame([$corrective], array_column($open, 'submission_id'));
    }

    // ── payment recognition ────────────────────────────────────────────────

    public function testOutgoingPaymentToTheVatAccountMarksTheLiabilityPaid(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 31_337);
        $tx = $this->fx->outgoingTransaction($account, 31_337, '2020-06-24', '12345678', '705-77628031');

        $result = $this->matcher->match($tx);

        self::assertSame('vat_payment', $result['status']);
        self::assertSame($id, $result['submission_id']);
        $row = $this->fx->submissionRow($id);
        self::assertSame('2020-06-24', $row['paid_at']);
        self::assertSame('31337.00', $row['paid_amount']);
        self::assertSame('bank', $row['paid_source']);
        self::assertSame($tx, (int) $row['paid_bank_transaction_id']);
        $bank = $this->fx->transaction($tx);
        self::assertSame('ignored', $bank['match_status'], 'the transaction is consumed, not left as an unmatched payment');
        self::assertSame('vat_payment', $bank['ignore_origin']);
        self::assertStringContainsString('Platba DPH za 2099-05', (string) $bank['ignore_note']);
        self::assertSame([], $this->payments->unpaidLiabilities($this->sid()));
    }

    public function testRecognitionUnderstandsPaddedGpcAccountAndIbanAndAMissingBankCode(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        foreach ([['0007050077628031', '0710'], ['CZ7407100007050077628031', null], ['705-77628031', null]] as $i => [$counterparty, $bank]) {
            $id = $this->filedReturn(5 + $i, 1_100 + $i);
            $tx = $this->fx->outgoingTransaction($account, 1_100 + $i, '2020-06-24', '12345678', $counterparty, $bank);

            self::assertSame('vat_payment', $this->matcher->match($tx)['status'], "counterparty {$counterparty}");
            self::assertSame('bank', $this->fx->submissionRow($id)['paid_source']);
        }
    }

    /** @return array<string, array{float, ?string, ?string, ?string}> amount, VS, counterparty account, bank */
    public static function notAVatPayment(): array
    {
        return [
            'different amount'     => [31_336.0, '12345678', '705-77628031', '0710'],
            'part payment'         => [10_000.0, '12345678', '705-77628031', '0710'],
            'other variable symbol' => [31_337.0, '87654321', '705-77628031', '0710'],
            'no variable symbol'   => [31_337.0, null, '705-77628031', '0710'],
            'other office'         => [31_337.0, '12345678', '705-77628111', '0710'],
            'other tax prefix'     => [31_337.0, '12345678', '721-77628031', '0710'],
            'not the ČNB'          => [31_337.0, '12345678', '705-77628031', '0800'],
            'no counterparty'      => [31_337.0, '12345678', null, null],
        ];
    }

    #[DataProvider('notAVatPayment')]
    public function testOtherOutgoingPaymentsAreLeftAlone(float $amount, ?string $vs, ?string $counterparty, ?string $bank): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 31_337);
        $tx = $this->fx->outgoingTransaction($account, $amount, '2020-06-24', $vs, $counterparty, $bank);

        $result = $this->matcher->match($tx);

        self::assertNotSame('vat_payment', $result['status']);
        self::assertNull($this->fx->submissionRow($id)['paid_at']);
        self::assertSame('unmatched', $this->fx->transaction($tx)['match_status']);
    }

    public function testIncomingMoneyFromTheTaxOfficeIsNotAPayment(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 31_337);
        $tx = $this->fx->outgoingTransaction($account, 31_337, '2020-06-24', '12345678', '705-77628031');
        $this->fx->pdo->exec("UPDATE bank_transactions SET amount = 31337.00 WHERE id = {$tx}");

        self::assertNotSame('vat_payment', $this->matcher->match($tx)['status']);
        self::assertNull($this->fx->submissionRow($id)['paid_at']);
    }

    public function testGeneratedButNotFiledReturnIsNotMatched(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 31_337);
        $tx = $this->fx->outgoingTransaction($account, 31_337, '2099-06-24', '12345678', '705-77628031');

        self::assertNotSame('vat_payment', $this->matcher->match($tx)['status']);
        self::assertNull($this->fx->submissionRow($id)['paid_at']);
        self::assertSame('unmatched', $this->fx->transaction($tx)['match_status']);
    }

    public function testAPaymentMadeBeforeTheFilingWasRecordedIsPickedUpWhenItIsRecorded(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 31_337);   // due 2099-06-25
        $paid = $this->fx->outgoingTransaction($account, 31_337, '2099-06-24', '12345678', '705-77628031');
        $otherAmount = $this->fx->outgoingTransaction($account, 31_336, '2099-06-24', '12345678', '705-77628031');
        $otherVs = $this->fx->outgoingTransaction($account, 31_337, '2099-06-24', '87654321', '705-77628031');
        $otherAccount = $this->fx->outgoingTransaction($account, 31_337, '2099-06-24', '12345678', '721-77628031');
        self::assertNull($this->fx->submissionRow($id)['paid_at'], 'nothing to pay yet');

        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        $row = $this->fx->submissionRow($id);
        self::assertSame('bank', $row['paid_source']);
        self::assertSame($paid, (int) $row['paid_bank_transaction_id']);
        self::assertSame('2099-06-24', $row['paid_at']);
        self::assertSame('ignored', $this->fx->transaction($paid)['match_status']);
        self::assertSame('vat_payment', $this->fx->transaction($paid)['ignore_origin']);
        foreach ([$otherAmount, $otherVs, $otherAccount] as $tx) {
            self::assertSame('unmatched', $this->fx->transaction($tx)['match_status'], 'the same exact-match rules apply');
        }
    }

    public function testReconsideringIsIdempotent(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 2_222);
        $recognizer = $this->fx->container->get(\MyInvoice\Service\Tax\VatPaymentRecognizer::class);
        $first = $this->fx->outgoingTransaction($account, 2_222, '2099-06-24', '12345678', '705-77628031');
        self::assertSame(1, $recognizer->reconsider($this->sid(), $id));
        $paidAt = $this->fx->submissionRow($id);

        // A second identical payment of the same source is no twin of the first one, so it stays for the user.
        $second = $this->fx->outgoingTransaction($account, 2_222, '2099-06-24', '12345678', '705-77628031');
        self::assertSame(0, $recognizer->reconsider($this->sid(), $id));

        self::assertSame($paidAt, $this->fx->submissionRow($id));
        self::assertSame($first, (int) $this->fx->submissionRow($id)['paid_bank_transaction_id']);
        self::assertSame('unmatched', $this->fx->transaction($second)['match_status']);
    }

    /** @return array<string, array{string, bool}> posted date (return of 2099-05, due 2099-06-25), recognised */
    public static function reconsiderWindow(): array
    {
        return [
            '61 days before the due date' => ['2099-04-25', false],
            '60 days before the due date' => ['2099-04-26', true],
            'on the due date'             => ['2099-06-25', true],
            '60 days after the due date'  => ['2099-08-24', true],
            '61 days after the due date'  => ['2099-08-25', false],
            'a year earlier'              => ['2098-06-24', false],
        ];
    }

    #[DataProvider('reconsiderWindow')]
    public function testOnlyPaymentsAroundTheDueDateAreReconsidered(string $postedAt, bool $recognised): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 5_555);
        $tx = $this->fx->outgoingTransaction($account, 5_555, $postedAt, '12345678', '705-77628031');

        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        self::assertSame($recognised, $this->fx->submissionRow($id)['paid_at'] !== null);
        self::assertSame($recognised ? 'ignored' : 'unmatched', $this->fx->transaction($tx)['match_status']);
    }

    public function testOnlyThePaymentFromTheSuppliersOwnAccountIsReconsidered(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 7_777);
        $foreign = $this->fx->outgoingTransaction(['account_number' => '99887766', 'bank_code' => '0100'], 7_777, '2099-06-24', '12345678', '705-77628031');
        $own = $this->fx->outgoingTransaction($account, 7_777, '2099-06-24', '12345678', '705-77628031');

        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        self::assertSame($own, (int) $this->fx->submissionRow($id)['paid_bank_transaction_id']);
        self::assertSame('unmatched', $this->fx->transaction($foreign)['match_status'], 'a statement of another account is none of this supplier\'s business');
    }

    public function testATransactionTheUserAlreadyMatchedIsNotReconsidered(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 8_888);
        $matched = $this->fx->outgoingTransaction($account, 8_888, '2099-06-24', '12345678', '705-77628031');
        $this->fx->pdo->exec("UPDATE bank_transactions SET match_status = 'manual' WHERE id = {$matched}");

        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        self::assertNull($this->fx->submissionRow($id)['paid_at']);
        self::assertSame('manual', $this->fx->transaction($matched)['match_status']);
    }

    public function testTheNoticeAndTheStatementOfAnEarlyPaymentAreBothConsumed(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->fx->dphSubmission(2099, 5, null, 6_666);
        $notice = $this->fx->outgoingTransaction($account, 6_666, '2099-06-23', '12345678', '705-77628031', '0710', 'email_notice');
        $statement = $this->fx->outgoingTransaction($account, 6_666, '2099-06-24', '12345678', '705-77628031', '0710', 'statement');

        $this->filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        self::assertSame('ignored', $this->fx->transaction($notice)['match_status']);
        self::assertSame('ignored', $this->fx->transaction($statement)['match_status']);
        $row = $this->fx->submissionRow($id);
        self::assertSame($statement, (int) $row['paid_bank_transaction_id'], 'the statement line is the authoritative record');
        self::assertSame('6666.00', $row['paid_amount'], 'and the amount is not counted twice');
    }

    public function testAPaymentOfTheDifferenceIsPickedUpWhenACorrectiveReturnIsRecorded(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $regular = $this->filedReturn(5, 4_000);
        $this->payments->markPaid($this->sid(), $regular, '2099-06-20', null, null);
        $corrective = $this->fx->dphSubmission(2099, 5, null, 5_000, 'O');
        $tx = $this->fx->outgoingTransaction($account, 1_000, '2099-06-24', '12345678', '705-77628031');

        $this->filing->markFiled($this->sid(), $corrective, '2020-01-10 10:00', 'epo', null, null);

        $row = $this->fx->submissionRow($corrective);
        self::assertSame($tx, (int) $row['paid_bank_transaction_id'], 'the 1 000 CZK still due after the 4 000 already paid');
        self::assertSame('1000.00', $row['paid_amount']);
    }

    public function testRecordingAFilingSurvivesAFailingRecognition(): void
    {
        $recognizer = $this->createStub(\MyInvoice\Service\Tax\VatPaymentRecognizer::class);
        $recognizer->method('reconsider')->willThrowException(new \RuntimeException('boom'));
        $filing = new TaxSubmissionFilingService($this->repo, $this->fx->container->get(\MyInvoice\Service\ActivityLogger::class), $recognizer);
        $id = $this->fx->dphSubmission(2099, 5, null, 1_234);

        $row = $filing->markFiled($this->sid(), $id, '2020-01-10 10:00', 'epo', null, null);

        self::assertSame('filed', $row['filing_status']);
    }

    public function testTheOldestPeriodWinsWhenTwoLiabilitiesHaveTheSameAmount(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $may = $this->filedReturn(5, 4_444);
        $april = $this->filedReturn(4, 4_444);
        $tx = $this->fx->outgoingTransaction($account, 4_444, '2020-06-24', '12345678', '705-77628031');

        $this->matcher->match($tx);

        self::assertSame($tx, (int) $this->fx->submissionRow($april)['paid_bank_transaction_id']);
        self::assertNull($this->fx->submissionRow($may)['paid_at']);
    }

    public function testTheSecondRecordOfTheSamePaymentIsConsumedAsATwin(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 6_666);
        $notice = $this->fx->outgoingTransaction($account, 6_666, '2020-06-24', '12345678', '705-77628031', '0710', 'email_notice');
        $statement = $this->fx->outgoingTransaction($account, 6_666, '2020-06-25', '12345678', '705-77628031', '0710', 'statement');

        self::assertSame('vat_payment', $this->matcher->match($notice)['status']);
        $second = $this->matcher->match($statement);

        self::assertSame('vat_payment', $second['status']);
        self::assertTrue($second['twin']);
        self::assertSame('ignored', $this->fx->transaction($statement)['match_status'], 'the statement line is consumed as well');
        $row = $this->fx->submissionRow($id);
        self::assertSame($statement, (int) $row['paid_bank_transaction_id'], 'the official statement becomes the authoritative record');
        self::assertSame('2020-06-24', $row['paid_at'], 'the payment date is kept');
        self::assertSame('6666.00', $row['paid_amount'], 'and the amount is not counted twice');
    }

    public function testUnmatchingTheTransactionReopensTheLiability(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 9_999);
        $tx = $this->fx->outgoingTransaction($account, 9_999, '2020-06-24', '12345678', '705-77628031');
        $this->matcher->match($tx);
        self::assertNotNull($this->fx->submissionRow($id)['paid_at']);

        $action = $this->fx->container->get(BankStatementAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/api/bank-transactions/{$tx}/unmatch")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => 'admin']);
        $response = $action->unmatch($request, new Response(), ['id' => $tx]);

        self::assertSame(200, $response->getStatusCode());
        $row = $this->fx->submissionRow($id);
        self::assertNull($row['paid_at']);
        self::assertNull($row['paid_bank_transaction_id']);
        self::assertSame('unmatched', $this->fx->transaction($tx)['match_status']);
        self::assertSame([$id], array_column($this->payments->unpaidLiabilities($this->sid()), 'submission_id'));
    }

    public function testClearingTheManualFlagOfARecognisedPaymentFreesTheBankTransaction(): void
    {
        $account = $this->fx->bankAccountOrSkip();
        $id = $this->filedReturn(5, 7_777);
        $tx = $this->fx->outgoingTransaction($account, 7_777, '2020-06-24', '12345678', '705-77628031');
        $this->matcher->match($tx);

        $this->payments->clearPaid($this->sid(), $id, null);

        self::assertSame('unmatched', $this->fx->transaction($tx)['match_status']);
        self::assertNull($this->fx->transaction($tx)['ignore_origin']);
    }

    // ── dashboard feed ─────────────────────────────────────────────────────

    public function testDashboardFeedAsksToPayUntilItIsPaid(): void
    {
        $id = $this->filedReturn(5, 55_555);
        $crm = $this->fx->container->get(CrmAggregationService::class);
        $items = fn (): array => array_values(array_filter(
            $crm->actionItems($this->sid(), null, new \DateTimeImmutable('2099-06-10'))['items'],
            static fn (array $i): bool => $i['type'] === 'vat_payment',
        ));

        $open = $items();
        self::assertCount(1, $open);
        self::assertSame('Zaplaťte DPH', $open[0]['title']);
        self::assertStringContainsString('55 555 Kč', $open[0]['hint']);
        self::assertStringContainsString('2099-06-25', $open[0]['hint']);
        self::assertSame(15, $open[0]['days']);

        $this->payments->markPaid($this->sid(), $id, '2020-06-20', null, null);
        self::assertSame([], $items());
    }

    public function testDashboardFeedIgnoresReturnsThatAreNotFiledAndRefunds(): void
    {
        $this->fx->dphSubmission(2099, 5, null, 12_000);
        $this->filedReturn(6, -3_000);
        $crm = $this->fx->container->get(CrmAggregationService::class);

        $types = array_column($crm->actionItems($this->sid(), null, new \DateTimeImmutable('2099-06-10'))['items'], 'type');

        self::assertNotContains('vat_payment', $types);
    }

    private function assertPaymentError(string $code, callable $call, string $why = ''): void
    {
        try {
            $call();
            self::fail("Expected TaxFilingException {$code} {$why}");
        } catch (TaxFilingException $e) {
            self::assertSame($code, $e->errorCode, $why);
        }
    }
}
