<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Action\Report\VatChecksAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\DphFiling;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Service\Tax\VatPaymentService;
use MyInvoice\Service\Vat\RegistrationDeductionService;
use MyInvoice\Tests\Support\FilingFixture;
use MyInvoice\Tests\Support\FixedClock;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Supplementary DPH return (DPHDP3 form D, § 141 DŘ): the differences against the last FILED return of the period
 * (B, O or an earlier D, cumulatively), the refusals, the filing chain and the payment card.
 *
 * Isolated period: June 2050 (the report endpoint accepts years up to 2050); everything is removed in tearDown.
 */
#[Group('integration')]
final class SupplementaryReturnTest extends TestCase
{
    private const YEAR = 2050;
    private const MONTH = 6;
    private const DISCOVERED = '15.10.2026';

    private ?FilingFixture $fx = null;
    private DphPriznaniBuilder $dph;
    private TaxSubmissionArchiver $archiver;
    private TaxSubmissionFilingService $filing;
    private TaxSubmissionRepository $repo;
    private VatPaymentService $payments;
    private int $customer = 0;
    private int $vendor = 0;
    private int $filedSeq = 0;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $c = $this->fx->container;
        // "Today" is after the regular deadline of June 2050 (25. 7.), so a supplementary return is due. Bound before the
        // builder (and the report action) are resolved from the container.
        $this->clock = new FixedClock('2050-09-01 12:00:00');
        $c->set(ClockInterface::class, $this->clock);
        $this->dph = $c->get(DphPriznaniBuilder::class);
        $this->archiver = $c->get(TaxSubmissionArchiver::class);
        $this->filing = $c->get(TaxSubmissionFilingService::class);
        $this->repo = $c->get(TaxSubmissionRepository::class);
        $this->payments = $c->get(VatPaymentService::class);
        $this->fx->payer();
        $this->fx->configureSupplier(['dic' => 'CZ12345678', 'financial_office_code' => '451', 'taxpayer_type' => 'fo', 'vat_period' => 'monthly']);
        $this->customer = $this->fx->client('Odběratel s DIČ', null, 'CZ11111118');
        $this->vendor = $this->fx->client('Dodavatel s DIČ', null, 'CZ22222220');

        // Sale 20 000 + 4 200 (row 1), received invoice 10 000 + 2 100 (row 40): tax to pay 2 100.
        $this->fx->sale('2050060001', $this->customer, '2050-06-10', 20_000, 4_200, 21);
        $this->fx->purchase('P-2050-0601', $this->vendor, '2050-06-12', 10_000, 2_100, 21, '40');
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    private function sid(): int
    {
        return $this->fx->supplierId;
    }

    /** Runs `$call`, which must be refused with a {@see TaxFilingException}; returns the exception. */
    private function refusal(callable $call): TaxFilingException
    {
        try {
            $call();
        } catch (TaxFilingException $e) {
            return $e;
        }
        self::fail('Expected the call to be refused.');
    }

    /** Builds + archives a return of June 2050 the way the report endpoint does and returns the archive id and the build. */
    private function generate(string $form = 'B', ?string $dZjist = null, ?string $reason = null): array
    {
        $result = $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', new DphFiling($form, $dZjist, $reason));
        $archived = $this->archiver->archive($this->sid(), 'dphdp3', self::YEAR, self::MONTH, null, $result['xml'], $result['summary'], $this->fx->userId);
        $this->fx->trackSubmission($archived['submission_id']);
        self::assertSame('passed', $archived['validation_status'], implode('; ', $archived['validation_errors']));
        return ['id' => $archived['submission_id']] + $result;
    }

    /** Generates and files; every filing gets a later time than the previous one. */
    private function file(string $form = 'B', ?string $dZjist = null, ?string $reason = null): array
    {
        $generated = $this->generate($form, $dZjist, $reason);
        $this->filing->markFiled($this->sid(), $generated['id'], sprintf('2026-10-%02d 10:00', 1 + ++$this->filedSeq), 'epo', null, $this->fx->userId);
        return $generated;
    }

    private function supplement(string $reason = 'Zapomenutý doklad'): array
    {
        return $this->file('D', self::DISCOVERED, $reason);
    }

    /** @return array<string,string> */
    private function attrs(array $result, string $element): array
    {
        $node = (new \SimpleXMLElement($result['xml']))->DPHDP3->$element;
        return $node->getName() === '' ? [] : array_map('strval', iterator_to_array($node->attributes()));
    }

    private function addPurchase(string $number, float $base, float $vat, string $date = '2050-06-20'): void
    {
        $this->fx->purchase($number, $this->vendor, $date, $base, $vat, 21, '40');
    }

    public function testDifferencesAreListedAgainstTheFiledRegularReturn(): void
    {
        $b = $this->file();
        self::assertSame(2100, $b['summary']['line_64_dano_da']);

        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $d = $this->generate('D', self::DISCOVERED, 'Zapomenutý přijatý doklad P-2050-0602');

        self::assertSame(['pln23' => '5000', 'odp_tuz23_nar' => '1050', 'odp_sum_nar' => '1050'], $this->attrs($d, 'Veta4'), 'only the difference of row 40');
        self::assertSame([], $this->attrs($d, 'Veta1'), 'unchanged rows are not repeated');
        $veta6 = $this->attrs($d, 'Veta6');
        self::assertSame(['dan_zocelk' => '0', 'odp_zocelk' => '1050', 'dano' => '-1050'], $veta6, 'row 66 is the signed difference; rows 64 / 65 stay empty');
        $vetaD = $this->attrs($d, 'VetaD');
        self::assertSame('D', $vetaD['dapdph_forma']);
        self::assertSame(self::DISCOVERED, $vetaD['d_zjist']);
        self::assertSame('A', $vetaD['trans']);

        $summary = $d['summary'];
        self::assertSame('D', $summary['form']);
        self::assertSame(2100, $summary['supplementary']['known_tax']);
        self::assertSame(1050, $summary['supplementary']['new_tax']);
        self::assertSame(-1050, $summary['supplementary']['difference']);
        self::assertSame(-1050, $summary['line_66_dano']);
        self::assertSame(0, $summary['line_64_dano_da']);
        self::assertSame($b['id'], $summary['supplementary']['based_on']['submission_id']);
        self::assertSame(15_000.0, $summary['full_lines'][40]['base'], 'the whole state the return brings the tax to');
        self::assertSame('2026-11-30', $summary['submission_deadline'], 'end of the month after the discovery');
    }

    public function testIncreaseOfTheTaxIsAPositiveRow66(): void
    {
        $this->file();
        $this->fx->sale('2050060002', $this->customer, '2050-06-25', 5_000, 1_050, 21);

        $d = $this->generate('D', self::DISCOVERED, 'Nezapočtená faktura');

        self::assertSame(['obrat23' => '5000', 'dan23' => '1050'], $this->attrs($d, 'Veta1'));
        self::assertSame(['dan_zocelk' => '1050', 'odp_zocelk' => '0', 'dano' => '1050'], $this->attrs($d, 'Veta6'));
        self::assertSame([], $this->attrs($d, 'Veta4'));
        self::assertSame(1050, $d['summary']['line_66_dano']);
    }

    public function testCorrectiveReturnReplacesTheBaseOfTheDifferences(): void
    {
        $this->file('B');
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $o = $this->file('O');                          // replaces B: now the known state includes P-2050-0602
        self::assertSame(1050, $o['summary']['line_64_dano_da']);

        $this->addPurchase('P-2050-0603', 1_000, 210, '2050-06-21');
        $d = $this->generate('D', self::DISCOVERED, 'Další doklad');

        self::assertSame(['pln23' => '1000', 'odp_tuz23_nar' => '210', 'odp_sum_nar' => '210'], $this->attrs($d, 'Veta4'));
        self::assertSame($o['id'], $d['summary']['supplementary']['based_on']['submission_id']);
        self::assertSame(1050, $d['summary']['supplementary']['known_tax']);
    }

    public function testSecondSupplementaryReturnIsCumulativeAgainstTheFirst(): void
    {
        $b = $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $d1 = $this->supplement('První doplnění');
        self::assertSame(-1050, $d1['summary']['line_66_dano']);

        $this->addPurchase('P-2050-0603', 1_000, 210, '2050-06-21');
        $d2 = $this->generate('D', '20.10.2026', 'Druhé doplnění');

        self::assertSame(['pln23' => '1000', 'odp_tuz23_nar' => '210', 'odp_sum_nar' => '210'], $this->attrs($d2, 'Veta4'), 'only what is new since the first D');
        self::assertSame($d1['id'], $d2['summary']['supplementary']['based_on']['submission_id']);
        self::assertSame(1050, $d2['summary']['supplementary']['known_tax'], 'the tax the first D brought it to');
        self::assertSame(840, $d2['summary']['supplementary']['new_tax']);
        self::assertSame(-210, $d2['summary']['line_66_dano']);
        self::assertSame(16_000.0, $d2['summary']['full_lines'][40]['base']);

        // Several supplementary returns of one period can be filed one after another.
        $this->filing->markFiled($this->sid(), $d2['id'], '2026-10-25 10:00', 'epo', null, $this->fx->userId);
        $filed = array_column($this->repo->filedForPeriod($this->sid(), 'dphdp3', self::YEAR, self::MONTH, null), 'submission_form', 'id');
        self::assertSame([$b['id'] => 'B', $d1['id'] => 'D', $d2['id'] => 'D'], $filed);
    }

    public function testRefusedWithoutAFiledReturnOfThePeriod(): void
    {
        try {
            $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', new DphFiling('D', self::DISCOVERED, 'důvod'));
            self::fail('no filed return');
        } catch (TaxFilingException $e) {
            self::assertSame('no_filed_return', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        // A generated (not filed) regular return is not a base either.
        $this->generate('B');
        $this->expectException(TaxFilingException::class);
        $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', new DphFiling('D', self::DISCOVERED, 'důvod'));
    }

    public function testBeforeTheRegularDeadlineACorrectiveReturnIsRequired(): void
    {
        $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $filing = new DphFiling('D', self::DISCOVERED, 'důvod');

        // The deadline of June 2050 is 25. 7. 2050 (Monday): up to and including that day only the corrective return replaces a return.
        foreach (['2050-07-01', '2050-07-25'] as $today) {
            $this->clock->set($today . ' 12:00:00');
            $e = $this->refusal(fn () => $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', $filing));
            self::assertSame('corrective_return_required', $e->errorCode, $today);
            self::assertStringContainsString('opravným přiznáním', $e->getMessage());
        }

        $this->clock->set('2050-07-26 12:00:00');
        self::assertSame('D', $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', $filing)['summary']['form'], 'allowed from the day after the deadline');
    }

    public function testSupplementaryReturnCannotBeFiledBeforeItsBase(): void
    {
        $b = $this->file();                                   // filed 2026-10-02 10:00
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $d = $this->generate('D', self::DISCOVERED, 'Zapomenutý doklad');

        foreach (['2026-10-01 09:00', '2026-10-02 10:00'] as $tooEarly) {
            $e = $this->refusal(fn () => $this->filing->markFiled($this->sid(), $d['id'], $tooEarly, 'epo', null, $this->fx->userId));
            self::assertSame('filed_before_base', $e->errorCode, $tooEarly);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame('generated', $this->repo->findMeta($d['id'], $this->sid())['filing_status']);

        $this->filing->markFiled($this->sid(), $d['id'], '2026-10-02 10:01', 'epo', null, $this->fx->userId);
        $filed = $this->repo->filedForPeriod($this->sid(), 'dphdp3', self::YEAR, self::MONTH, null);
        self::assertSame([$b['id'], $d['id']], array_column($filed, 'id'));
        self::assertTrue((new \MyInvoice\Service\Tax\FiledReturnChain($filed))->isInForce($d['id']), 'filed after its base, the supplement is in force');
    }

    public function testFilingLocksTheFiledReturnsOfThePeriod(): void
    {
        $b = $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $d = $this->generate('D', self::DISCOVERED, 'Zapomenutý doklad');

        // Another connection holds the base return of the period (as a concurrent filing of a supplementary return would): this filing
        // must wait for it. Only that one record is locked (no index gaps), so nothing else but the lock on the filed returns can make it wait.
        $other = new Connection($this->fx->container->get(\MyInvoice\Infrastructure\Config\Config::class));
        $pdo = $other->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM tax_submissions WHERE id = ? FOR UPDATE')->execute([$b['id']]);
            $this->fx->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $this->filing->markFiled($this->sid(), $d['id'], '2026-10-20 10:00', 'epo', null, $this->fx->userId);
                self::fail('the filing must wait for the lock on the filed returns of the period');
            } catch (\PDOException $e) {
                self::assertStringContainsString('Lock wait timeout', $e->getMessage());
            }
        } finally {
            $pdo->rollBack();
            $other->close();
            $this->fx->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertSame('generated', $this->repo->findMeta($d['id'], $this->sid())['filing_status']);
    }

    public function testBaseWithoutStoredRowsIsRefused(): void
    {
        // A return archived before the summary carried its rows: a difference against it would restate the whole return.
        $legacy = $this->fx->submission('dphdp3', self::YEAR, self::MONTH, null, 'B', ['tax_due' => 2100.0, 'line_64_dano_da' => 2100]);
        $this->filing->markFiled($this->sid(), $legacy, '2026-10-02 10:00', 'epo', null, $this->fx->userId);

        $e = $this->refusal(fn () => $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', new DphFiling('D', self::DISCOVERED, 'důvod')));
        self::assertSame('base_without_lines', $e->errorCode);
        self::assertStringContainsString('řádky', $e->getMessage());
    }

    public function testRefusedWhenNothingChangedSinceTheLastFiledReturn(): void
    {
        $this->file();

        try {
            $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'monthly', new DphFiling('D', self::DISCOVERED, 'důvod'));
            self::fail('nothing changed');
        } catch (TaxFilingException $e) {
            self::assertSame('nothing_changed', $e->errorCode);
        }
    }

    public function testRequiresDiscoveryDateReasonAndAPastDate(): void
    {
        foreach ([[null, 'důvod'], [self::DISCOVERED, ''], [self::DISCOVERED, str_repeat('x', 2001)], ['01.01.2999', 'důvod'], ['31.02.2026', 'důvod'], ['2026-10-15', 'důvod']] as [$dZjist, $reason]) {
            try {
                new DphFiling('D', $dZjist, $reason);
                self::fail('invalid supplementary filing');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testReasonIsPrintedAsTheLinesOfTheTextAttachment(): void
    {
        $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $reason = 'Při kontrole účetnictví jsem zjistil, že přijatý daňový doklad P-2050-0602 od dodavatele nebyl zahrnut do původního přiznání.';

        $d = $this->generate('D', self::DISCOVERED, $reason);

        $lines = iterator_to_array((new \SimpleXMLElement($d['xml']))->DPHDP3->VetaR, false);
        self::assertCount(2, $lines);
        foreach ($lines as $i => $line) {
            self::assertSame('D', (string) $line['kod_sekce']);
            self::assertSame((string) ($i + 1), (string) $line['poradi']);
            self::assertLessThanOrEqual(72, mb_strlen((string) $line['t_prilohy']));
        }
        self::assertSame($reason, implode(' ', array_map(static fn ($l): string => (string) $l['t_prilohy'], $lines)));
    }

    public function testPaymentCardUsesTheDifferenceAndDoesNotSupersedeTheRegularReturn(): void
    {
        $b = $this->file();
        $this->payments->markPaid($this->sid(), $b['id'], '2026-10-20', null, $this->fx->userId);
        $this->fx->sale('2050060002', $this->customer, '2050-06-25', 5_000, 1_050, 21);
        $d = $this->supplement('Nezapočtená faktura');

        $card = $this->payments->card($this->repo->findMeta($d['id'], $this->sid()));

        self::assertSame('payment', $card['kind']);
        self::assertSame(1050, $card['amount']);
        self::assertSame(1050, $card['amount_due'], 'the payment of the regular return is not deducted');
        self::assertSame('unpaid', $card['state']);
        self::assertTrue($card['payable']);
        self::assertSame('2026-11-30', $card['due_date'], '§ 141 odst. 7 DŘ: the end of the month after the discovery');

        $bCard = $this->payments->card($this->repo->findMeta($b['id'], $this->sid()));
        self::assertSame('paid', $bCard['state'], 'the regular return is still in force');
        self::assertSame(2100, $bCard['amount']);

        $unpaid = array_column($this->payments->unpaidLiabilities($this->sid()), 'amount', 'submission_id');
        self::assertSame([$d['id'] => 1050], array_intersect_key($unpaid, [$b['id'] => 1, $d['id'] => 1]));

        $paid = $this->payments->markPaid($this->sid(), $d['id'], '2026-11-20', null, $this->fx->userId);
        self::assertSame('paid', $paid['state']);
    }

    public function testPreFilingChecksShowTheRegularReturnAndTheSupplementsSeparately(): void
    {
        $b = $this->file();
        $this->fx->sale('2050060002', $this->customer, '2050-06-25', 5_000, 1_050, 21);
        $d = $this->supplement('Nezapočtená faktura');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/vat-checks')
            ->withQueryParams(['form' => 'dphdp3', 'year' => '2050', 'month' => '6', 'period' => 'monthly'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => 'admin']);

        $data = json_decode((string) $this->fx->container->get(VatChecksAction::class)($request, new Response())->getBody(), true);

        self::assertSame($d['id'], $data['filing']['id'], 'the last filed return is the baseline of the changes');
        self::assertSame($b['id'], $data['payment']['submission_id'], 'the payment of the regular return is not replaced by the supplement');
        self::assertSame(2100, $data['payment']['amount']);
        self::assertSame([$d['id']], array_column($data['supplementary_payments'], 'submission_id'));
        self::assertSame(1050, $data['supplementary_payments'][0]['amount']);
    }

    public function testNegativeDifferenceIsARefundNotAPayment(): void
    {
        $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $d = $this->supplement();

        $card = $this->payments->card($this->repo->findMeta($d['id'], $this->sid()));

        self::assertSame('refund', $card['kind']);
        self::assertSame(1050, $card['amount']);
        self::assertSame('refund', $card['state']);
        self::assertFalse($card['payable']);
    }

    public function testSupplementaryReturnCanOnlyBeFiledOnTopOfItsBase(): void
    {
        $b = $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);
        $stale = $this->generate('D', self::DISCOVERED, 'Vygenerované první');
        $this->generate('D', self::DISCOVERED, 'Vygenerované druhé');
        $d1 = $this->supplement('Podané'); // another D is filed meanwhile

        try {
            $this->filing->markFiled($this->sid(), $stale['id'], '2026-10-28 10:00', 'epo', null, $this->fx->userId);
            self::fail('a D computed against an older base must not be filed');
        } catch (TaxFilingException $e) {
            self::assertSame('stale_supplementary', $e->errorCode);
        }
        self::assertSame('generated', $this->repo->findMeta($stale['id'], $this->sid())['filing_status']);

        // The base of a filed D cannot be un-filed under it; un-filing the D first releases it.
        try {
            $this->filing->revertFiling($this->sid(), $b['id'], $this->fx->userId);
            self::fail('the base of a filed D cannot be un-filed');
        } catch (TaxFilingException $e) {
            self::assertSame('later_supplementary', $e->errorCode);
        }
        $this->filing->revertFiling($this->sid(), $d1['id'], $this->fx->userId);
        self::assertSame('generated', $this->filing->revertFiling($this->sid(), $b['id'], $this->fx->userId)['filing_status']);
    }

    public function testQuarterlyFilerSupplementsTheReturnOfTheQuarter(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        $b = $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'quarterly');
        $archived = $this->archiver->archive($this->sid(), 'dphdp3', self::YEAR, null, 2, $b['xml'], $b['summary'], $this->fx->userId);
        $this->fx->trackSubmission($archived['submission_id']);
        $this->filing->markFiled($this->sid(), $archived['submission_id'], '2026-10-02 10:00', 'epo', null, $this->fx->userId);

        $this->addPurchase('P-2050-0602', 5_000, 1_050, '2050-05-20');
        $d = $this->dph->build($this->sid(), self::YEAR, self::MONTH, 'quarterly', new DphFiling('D', self::DISCOVERED, 'Doklad z května'));

        self::assertSame('2', $this->attrs($d, 'VetaD')['ctvrt']);
        self::assertSame(['pln23' => '5000', 'odp_tuz23_nar' => '1050', 'odp_sum_nar' => '1050'], $this->attrs($d, 'Veta4'));
        self::assertSame($archived['submission_id'], $d['summary']['supplementary']['based_on']['submission_id']);
    }

    public function testRegistrationDeductionAddedAfterFilingIsSuppliedAsRow45(): void
    {
        $deductions = $this->fx->container->get(RegistrationDeductionService::class);
        if (!$this->fx->db->hasTable('vat_registration_deductions')) {
            self::markTestSkipped('Migrace 9006 není aplikovaná.');
        }
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-06-01', 'payer']]);
        $b = $this->file();
        self::assertArrayNotHasKey('odp_rez_nar', $this->attrs($b, 'Veta4'));

        $item = $deductions->create($this->sid(), [
            'kind' => 'registration', 'event_date' => '2050-06-01', 'description' => 'Zásoby', 'asset_type' => 'inventory',
            'acquired_on' => '2050-03-01', 'tax_base' => 10_000, 'vat_amount' => 2_100,
        ], $this->fx->userId);
        $this->fx->trackDeduction($item['id']);

        $d = $this->generate('D', self::DISCOVERED, 'Zapomenutý odpočet při registraci (§ 79 ZDPH)');

        self::assertSame(['odp_rez_nar' => '2100', 'odp_sum_nar' => '2100'], $this->attrs($d, 'Veta4'));
        self::assertSame('-2100', $this->attrs($d, 'Veta6')['dano']);
    }

    public function testReportEndpointPreviewsAndDownloadsTheSupplementaryReturn(): void
    {
        $action = $this->fx->container->get(DphPriznaniAction::class);
        $query = ['year' => '2050', 'month' => '6', 'period' => 'monthly', 'form' => 'D', 'd_zjist' => self::DISCOVERED, 'reason' => 'Test'];

        $refused = $action->preview($this->request($query), new Response());
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('no_filed_return', json_decode((string) $refused->getBody(), true)['error']['code']);

        $this->file();
        $this->addPurchase('P-2050-0602', 5_000, 1_050);

        $preview = $action->preview($this->request($query), new Response());
        self::assertSame(200, $preview->getStatusCode());
        $data = json_decode((string) $preview->getBody(), true);
        self::assertSame('D', $data['summary']['form']);
        self::assertSame(-1050, $data['summary']['supplementary']['difference']);

        $missingReason = $action->preview($this->request(['reason' => ''] + $query), new Response());
        self::assertSame(400, $missingReason->getStatusCode());

        $download = $action->download($this->request($query), new Response());
        self::assertSame(200, $download->getStatusCode());
        self::assertStringContainsString('-D.xml', $download->getHeaderLine('Content-Disposition'));
        $rows = $this->fx->pdo->query("SELECT id, submission_form, validation_status FROM tax_submissions WHERE supplier_id = {$this->sid()} AND submission_form = 'D' AND period_year = 2050")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('passed', $rows[0]['validation_status']);
        $this->fx->trackSubmission((int) $rows[0]['id']);
    }

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/dphdp3')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => 'admin']);
    }
}
