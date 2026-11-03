<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\LateInterest;

use MyInvoice\Action\Codebook\RepoRatesAction;
use MyInvoice\Action\Invoice\LateInterestAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\LateInterest\CnbRepoRateFetcher;
use MyInvoice\Service\LateInterest\LateInterestException;
use MyInvoice\Service\LateInterest\LateInterestInvoiceCreator;
use MyInvoice\Service\LateInterest\LateInterestService;
use MyInvoice\Repository\RepoRateRepository;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Support\VatStatusFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Late-payment interest end to end against the real schema. Everything runs inside one transaction
 * that is rolled back in tearDown, so the database is left exactly as it was found.
 */
#[Group('integration')]
final class LateInterestIntegrationTest extends TestCase
{
    private const DUE = '2026-09-15';
    private const AS_OF = '2026-11-03';

    private ContainerInterface $c;
    private PDO $pdo;
    private int $supplierId;
    private int $czk;
    private int $eur;
    private int $userId;
    private int $businessClient;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php') && !is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->c = Bootstrap::buildApp()->getContainer();
            $this->pdo = $this->c->get(Connection::class)->pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $cur = $this->pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ?');
        $cur->execute([$this->supplierId, 'CZK']);
        $this->czk = (int) $cur->fetchColumn();
        $cur->execute([$this->supplierId, 'EUR']);
        $this->eur = (int) $cur->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czk === 0 || $this->eur === 0) {
            self::markTestSkipped('Chybí syntetická data (spusť ci-seed.php).');
        }
        $this->pdo->beginTransaction();
        VatStatusFixture::set($this->pdo, $this->supplierId, true);
        $this->businessClient = $this->client('12345678');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ───────────── fixtures ─────────────

    private function supplier(bool $enabled, bool $inReminders = true, bool $claimCost = false): void
    {
        $this->pdo->prepare(
            'UPDATE supplier SET late_interest_enabled = ?, late_interest_in_reminders = ?, late_interest_claim_fixed_cost = ? WHERE id = ?'
        )->execute([(int) $enabled, (int) $inReminders, (int) $claimCost, $this->supplierId]);
    }

    private function client(?string $ic, ?int $override = null, string $name = 'Úrok Test s.r.o.'): int
    {
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, ic, street, city, zip, main_email, country_id, currency_default_id, late_interest)
             VALUES (?, ?, ?, 'Testovací 1', 'Praha', '11000', 'client@example.test', (SELECT id FROM countries WHERE iso2 = 'CZ'), ?, ?)"
        )->execute([$this->supplierId, $name, $ic, $this->czk, $override]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $o */
    private function invoice(array $o = []): int
    {
        $o += [
            'client_id' => $this->businessClient, 'varsymbol' => 'LI' . random_int(1000, 9999) . random_int(100, 999),
            'due_date' => self::DUE, 'status' => 'sent', 'invoice_type' => 'invoice', 'total' => 10000,
            'currency_id' => $this->czk, 'late_interest' => null, 'paid_at' => null,
        ];
        $this->pdo->prepare(
            "INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                total_without_vat, total_vat, total_with_vat, status, late_interest, paid_at, created_by)
             VALUES (?, ?, ?, ?, '2026-08-01', '2026-08-01', ?, ?, ?, 0, ?, ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $o['varsymbol'], $o['invoice_type'], $o['client_id'], $o['due_date'], $o['currency_id'],
            $o['total'], $o['total'], $o['status'], $o['late_interest'], $o['paid_at'], $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function pay(int $invoiceId, string $date, float $amount): void
    {
        $this->pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, ?, ?, 'CZK', 'manual')"
        )->execute([$this->supplierId, $invoiceId, $date, $amount]);
    }

    private function load(int $id): array
    {
        return $this->c->get(InvoiceRepository::class)->find($id);
    }

    private function service(): LateInterestService
    {
        return $this->c->get(LateInterestService::class);
    }

    private function creator(): LateInterestInvoiceCreator
    {
        return $this->c->get(LateInterestInvoiceCreator::class);
    }

    private function rates(): RepoRateRepository
    {
        return $this->c->get(RepoRateRepository::class);
    }

    // ───────────── opt-in: off by default, overrides ─────────────

    public function testFeatureIsOffByDefaultAndProducesNothing(): void
    {
        $id = $this->invoice();
        $ev = $this->service()->evaluate($this->load($id), self::AS_OF);

        self::assertFalse($ev['enabled']);
        self::assertSame(LateInterestService::REASON_DISABLED, $ev['reason']);
        self::assertNull($ev['summary']);
        self::assertNull($this->service()->forReminder($this->load($id), self::AS_OF));
    }

    public function testSupplierOptInEnablesIt(): void
    {
        $this->supplier(true);
        $ev = $this->service()->evaluate($this->load($this->invoice()), self::AS_OF);

        self::assertTrue($ev['enabled']);
        self::assertSame('supplier', $ev['enabled_source']);
        self::assertSame(157.74, $ev['summary']['interest']);
        self::assertSame(49, $ev['summary']['days']);
    }

    public function testClientAndInvoiceOverridesFollowThePrecedence(): void
    {
        $off = $this->client('87654321', 0);
        $on = $this->client('11223344', 1);

        // supplier on, client off -> off
        $this->supplier(true);
        $ev = $this->service()->evaluate($this->load($this->invoice(['client_id' => $off])), self::AS_OF);
        self::assertFalse($ev['enabled']);
        self::assertSame('client', $ev['enabled_source']);
        self::assertFalse($ev['client_override']);

        // ... but the invoice override wins over the client
        $ev = $this->service()->evaluate($this->load($this->invoice(['client_id' => $off, 'late_interest' => 1])), self::AS_OF);
        self::assertTrue($ev['enabled']);
        self::assertSame('invoice', $ev['enabled_source']);
        self::assertNotNull($ev['summary']);

        // supplier off, client on -> on; invoice off wins again
        $this->supplier(false);
        self::assertTrue($this->service()->evaluate($this->load($this->invoice(['client_id' => $on])), self::AS_OF)['enabled']);
        self::assertFalse($this->service()->evaluate($this->load($this->invoice(['client_id' => $on, 'late_interest' => 0])), self::AS_OF)['enabled']);
    }

    public function testIneligibleDocumentsAreRefusedWithAReason(): void
    {
        $this->supplier(true);
        $cases = [
            LateInterestService::REASON_INVALID_TYPE  => $this->invoice(['invoice_type' => 'proforma']),
            LateInterestService::REASON_INVALID_STATE => $this->invoice(['status' => 'draft']),
            LateInterestService::REASON_NO_AMOUNT     => $this->invoice(['total' => 0]),
            LateInterestService::REASON_NOT_OVERDUE   => $this->invoice(['due_date' => self::AS_OF]),
        ];
        foreach ($cases as $reason => $id) {
            $ev = $this->service()->evaluate($this->load($id), self::AS_OF);
            self::assertSame($reason, $ev['reason'], $reason);
            self::assertNull($ev['summary']);
            self::assertNotEmpty($ev['message']);
        }
    }

    // ───────────── payments ─────────────

    public function testPartialPaymentsFromTheLedgerLowerTheBalance(): void
    {
        $this->supplier(true);
        $id = $this->invoice();
        $this->pay($id, '2026-10-10', 4000.0);

        $s = $this->service()->evaluate($this->load($id), self::AS_OF)['summary'];

        self::assertSame(126.84, $s['interest']);
        self::assertSame(6000.0, $s['outstanding']);
        self::assertCount(2, $s['segments']);
    }

    public function testLegacyPaidInvoiceWithoutPaymentRowsUsesPaidAt(): void
    {
        $this->supplier(true);
        $id = $this->invoice(['status' => 'paid', 'paid_at' => '2026-10-05']);

        $s = $this->service()->evaluate($this->load($id), self::AS_OF)['summary'];

        self::assertSame(64.38, $s['interest']);
        self::assertSame('2026-10-05', $s['settled_on']);
    }

    // ───────────── fixed cost: business customers only ─────────────

    public function testFixedCostIsClaimableOnlyForBusinessCustomersWhenTheOwnerOptedIn(): void
    {
        $consumer = $this->client(null, null, 'Soukromá osoba');

        $this->supplier(true, true, true);
        self::assertTrue($this->service()->evaluate($this->load($this->invoice()), self::AS_OF)['summary']['fixed_cost']['claimable']);
        self::assertFalse(
            $this->service()->evaluate($this->load($this->invoice(['client_id' => $consumer])), self::AS_OF)['summary']['fixed_cost']['claimable'],
            'a private person is not an entrepreneur (§ 3 NV 351/2013 Sb.)',
        );

        $this->supplier(true, true, false);
        self::assertFalse($this->service()->evaluate($this->load($this->invoice()), self::AS_OF)['summary']['fixed_cost']['claimable']);
    }

    // ───────────── reminders ─────────────

    public function testReminderBlockIsAbsentWhenOffHiddenOrTheRateIsNotKnownToBeCurrent(): void
    {
        $inv = $this->load($this->invoice());

        $this->supplier(false);
        self::assertNull($this->service()->forReminder($inv, self::AS_OF), 'feature off');

        $this->supplier(true, false);
        self::assertNull($this->service()->forReminder($inv, self::AS_OF), 'reminders option off');

        $this->supplier(true, true);
        self::assertSame(157.74, $this->service()->forReminder($inv, self::AS_OF)['interest']);
        self::assertNull($this->service()->forReminder($this->load($this->invoice(['late_interest' => 0])), self::AS_OF), 'invoice override off');
        self::assertNull($this->service()->forReminder($this->load($this->invoice(['invoice_type' => 'proforma'])), self::AS_OF), 'proforma');

        // The codebook was last verified before the half-year in which this delay began -> do not show.
        $this->pdo->prepare('UPDATE app_meta SET v = ? WHERE k = ?')->execute(['2026-06-30', RepoRateRepository::META_VERIFIED_ON]);
        self::assertNull($this->service()->forReminder($inv, self::AS_OF), 'rate not verified for H2 2026');
        $ev = $this->service()->evaluate($inv, self::AS_OF);
        self::assertFalse($ev['summary']['rate_verified']);
    }

    public function testReminderServicePassesTheInterestToTheTemplateOnlyWhenEnabled(): void
    {
        $captured = [];
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(function (string $code, string $locale, array $to, array $vars) use (&$captured): string {
            $captured[] = $vars;
            return 'ok';
        });
        $pdf = $this->createStub(InvoicePdfRenderer::class);
        $pdf->method('render')->willReturn('/tmp/li-test.pdf');
        $this->c->set(Mailer::class, $mailer);
        $this->c->set(InvoicePdfRenderer::class, $pdf);

        $id = $this->invoice();
        $this->supplier(false);
        $this->c->get(ReminderService::class)->send($id);
        $this->supplier(true, true, true);
        $this->c->get(ReminderService::class)->send($id);

        self::assertCount(2, $captured);
        self::assertNull($captured[0]['late_interest'], 'off: the reminder is built exactly as before');
        self::assertGreaterThan(0, $captured[1]['late_interest']['interest']);
        self::assertTrue($captured[1]['late_interest']['fixed_cost']['claimable']);
    }

    /** @return array<string,mixed> */
    private function reminderVars(array $invoice, ?array $interest): array
    {
        $vars = $this->c->get(InvoiceEmailVarsBuilder::class)->buildReminder($invoice, 49, 'cs', $interest);
        $vars['accent'] = '#3B2D83';
        return $vars;
    }

    public function testReminderTemplatesRenderIdenticallyToTheLegacyOnesWhenOff(): void
    {
        $real = dirname(__DIR__, 3) . '/templates/email';
        $legacy = dirname(__DIR__, 2) . '/Fixtures/email-legacy';
        $new = new Environment(new FilesystemLoader($real), ['autoescape' => 'html', 'cache' => false, 'strict_variables' => false]);
        $old = new Environment(new FilesystemLoader([$legacy, $real]), ['autoescape' => 'html', 'cache' => false, 'strict_variables' => false]);

        $invoice = $this->load($this->invoice());
        foreach (['cs', 'en'] as $lang) {
            foreach (['html', 'txt'] as $kind) {
                $tpl = "invoice_reminder.{$lang}.{$kind}.twig";
                foreach ([null, 'data:image/png;base64,AAAA'] as $qr) {
                    $vars = $this->reminderVars($invoice, null);
                    $vars['qr_data_uri'] = $qr;
                    self::assertSame($old->render($tpl, $vars), $new->render($tpl, $vars), "{$tpl} (qr=" . ($qr ? 'yes' : 'no') . ')');
                    unset($vars['late_interest']);
                    self::assertSame($old->render($tpl, $vars), $new->render($tpl, $vars), "{$tpl} without the variable");
                }
            }
        }
    }

    public function testReminderShowsAccruedInterestRateLegalBasisAndFixedCost(): void
    {
        $this->supplier(true, true, true);
        $invoice = $this->load($this->invoice());
        $interest = $this->service()->forReminder($invoice, self::AS_OF);
        $env = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'), ['autoescape' => 'html', 'cache' => false]);

        $cs = $env->render('invoice_reminder.cs.txt.twig', $this->reminderVars($invoice, $interest));
        self::assertStringContainsString('Úrok z prodlení ke dni 3.11.2026: 157,74 CZK', $cs);
        self::assertStringContainsString('roční sazba 11,75 %', $cs);
        self::assertStringContainsString('repo sazba ČNB 3,75 % platná k 1.7.2026 + 8 procentních bodů', $cs);
        self::assertStringContainsString('§ 1970 občanského zákoníku, § 2 nařízení vlády č. 351/2013 Sb.', $cs);
        self::assertStringContainsString('Paušální náhrada nákladů spojených s uplatněním pohledávky: 1 200,00 Kč', $cs);

        $en = $env->render('invoice_reminder.en.html.twig', $this->reminderVars($invoice, $interest));
        self::assertStringContainsString('Late-payment interest as of Nov 3, 2026', $en);
        self::assertStringContainsString('157.74 CZK', $en);
        self::assertStringContainsString('Government Regulation No. 351/2013 Sb.', $en);

        // Without the fixed-cost option the line disappears.
        $this->supplier(true, true, false);
        $noCost = $env->render('invoice_reminder.cs.txt.twig', $this->reminderVars($invoice, $this->service()->forReminder($invoice, self::AS_OF)));
        self::assertStringNotContainsString('Paušální náhrada', $noCost);
    }

    // ───────────── interest invoice ─────────────

    public function testInterestInvoiceIsAVatFreeDraftThatReferencesTheOriginalAndIsNeverIssued(): void
    {
        $this->supplier(true, true, true);
        $srcId = $this->invoice(['varsymbol' => 'FA-2609']);
        $source = $this->load($srcId);

        $r = $this->creator()->create($source, self::AS_OF, true, $this->userId);
        $d = $this->load($r['invoice_id']);

        self::assertSame('draft', $d['status'], 'never issued automatically');
        self::assertNull($d['varsymbol']);
        self::assertNull($d['sent_at']);
        self::assertSame('invoice', $d['invoice_type']);
        self::assertSame((int) $source['client_id'], $d['client_id']);
        self::assertSame(0.0, $d['total_vat']);
        self::assertSame(1357.74, $d['total_with_vat']);
        self::assertCount(2, $d['items'], 'interest line + fixed cost line');
        foreach ($d['items'] as $item) {
            self::assertEquals(0, $item['vat_rate_snapshot']);
            self::assertEquals(0, $item['total_vat']);
            self::assertSame(InvoiceVatAdvisor::OUTSIDE_VAT_SCOPE, $item['vat_classification_code']);
        }
        self::assertStringContainsString('FA-2609', $d['items'][0]['description']);
        self::assertStringContainsString('16. 9. 2026 – 3. 11. 2026', $d['items'][0]['description']);
        self::assertEqualsWithDelta(157.74, (float) $d['items'][0]['total_with_vat'], 0.001);
        self::assertStringContainsString('§ 3 nařízení vlády č. 351/2013 Sb.', $d['items'][1]['description']);
        self::assertEqualsWithDelta(1200.0, (float) $d['items'][1]['total_with_vat'], 0.001);
        self::assertStringContainsString('FA-2609', (string) $d['note_above_items']);
        self::assertStringContainsString('11,75 %', (string) $d['note_above_items']);
        self::assertStringContainsString('nejsou předmětem daně z přidané hodnoty', (string) $d['note_below_items']);
        self::assertSame([], $r['warnings']);
    }

    public function testSecondRunBillsOnlyTheNewDaysAndTheFixedCostOnlyOnce(): void
    {
        $this->supplier(true, true, true);
        $srcId = $this->invoice(['varsymbol' => 'FA-2610']);

        $first = $this->creator()->create($this->load($srcId), self::AS_OF, true, $this->userId);
        self::assertSame(1200.0, $first['fixed_cost']);

        // Same day again: nothing new to bill.
        try {
            $this->creator()->create($this->load($srcId), self::AS_OF, true, $this->userId);
            self::fail('expected the second run to be refused');
        } catch (LateInterestException $e) {
            self::assertStringContainsString('vyúčtován do 3. 11. 2026', $e->getMessage());
        }

        // Ten days later: only days 4.-13.11., no second fixed cost.
        $second = $this->creator()->create($this->load($srcId), '2026-11-13', true, $this->userId);
        self::assertSame('2026-11-04', $second['period_from']);
        self::assertSame('2026-11-13', $second['period_to']);
        self::assertNull($second['fixed_cost']);
        self::assertSame(32.19, $second['interest'], '10 000 * 0.1175 * 10 / 365');
        self::assertCount(1, $this->load($second['invoice_id'])['items']);
    }

    public function testDeletingOrCancellingTheInterestInvoiceReleasesTheCoverage(): void
    {
        $this->supplier(true);
        $srcId = $this->invoice();
        $first = $this->creator()->create($this->load($srcId), self::AS_OF, false, $this->userId);

        // draft deleted -> claim cascades away -> the same days can be billed again
        $this->c->get(InvoiceRepository::class)->delete($first['invoice_id']);
        $again = $this->creator()->create($this->load($srcId), self::AS_OF, false, $this->userId);
        self::assertSame('2026-09-16', $again['period_from']);

        // cancelled interest invoice no longer counts either
        $this->pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$again['invoice_id']]);
        self::assertSame([], $this->service()->claims($srcId));
        $third = $this->creator()->create($this->load($srcId), self::AS_OF, false, $this->userId);
        self::assertSame('2026-09-16', $third['period_from']);
    }

    public function testFixedCostLineIsOmittedForConsumersAndWhenTheCallerDeclinesIt(): void
    {
        $this->supplier(true, true, true);
        $consumer = $this->client(null, null, 'Soukromá osoba');

        $c = $this->creator()->create($this->load($this->invoice(['client_id' => $consumer])), self::AS_OF, true, $this->userId);
        self::assertNull($c['fixed_cost']);
        self::assertCount(1, $this->load($c['invoice_id'])['items']);

        $declined = $this->creator()->create($this->load($this->invoice()), self::AS_OF, false, $this->userId);
        self::assertNull($declined['fixed_cost']);
    }

    public function testNoInterestIsComputedOnAnInterestInvoice(): void
    {
        $this->supplier(true);
        $interestInvoice = $this->creator()->create($this->load($this->invoice()), self::AS_OF, false, $this->userId)['invoice_id'];
        $this->pdo->prepare("UPDATE invoices SET status = 'sent', varsymbol = 'UROK1' WHERE id = ?")->execute([$interestInvoice]);

        // Civil Code § 1806: interest on interest only if agreed. Inheriting the supplier opt-in ...
        $ev = $this->service()->evaluate($this->load($interestInvoice), '2030-01-01');
        self::assertSame(LateInterestService::REASON_INTEREST_INVOICE, $ev['reason']);
        self::assertNull($ev['summary']);
        self::assertNull($this->service()->forReminder($this->load($interestInvoice), '2030-01-01'));

        // ... and even with the per-invoice override forced on.
        $this->c->get(InvoiceRepository::class)->setLateInterest($interestInvoice, true);
        $ev = $this->service()->evaluate($this->load($interestInvoice), '2030-01-01');
        self::assertSame(LateInterestService::REASON_INTEREST_INVOICE, $ev['reason']);
        self::assertNull($ev['summary']);
    }

    public function testForeignCurrencyInvoiceBillsInterestInItsCurrencyAndConvertsTheFixedCost(): void
    {
        $this->supplier(true, true, true);
        $this->pdo->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (CURDATE(), ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute(['EUR', 25.0]);
        $srcId = $this->invoice(['currency_id' => $this->eur, 'total' => 1000]);

        $r = $this->creator()->create($this->load($srcId), self::AS_OF, true, $this->userId);
        $d = $this->load($r['invoice_id']);

        self::assertSame('EUR', $d['currency']);
        self::assertSame(15.77, $r['interest'], '1 000 EUR * 0.1175 * 49 / 365, no conversion of the interest');
        self::assertSame(48.0, $r['fixed_cost'], 'CZK 1 200 at 25 CZK/EUR');
        self::assertStringContainsString('1 200 Kč přepočteno kurzem ČNB', $d['items'][1]['description']);
        self::assertEqualsWithDelta(25.0, (float) $d['exchange_rate'], 0.0001);
    }

    public function testUnverifiedRepoCodebookBlocksBilling(): void
    {
        $this->supplier(true);
        $id = $this->invoice();
        $this->pdo->prepare('UPDATE app_meta SET v = ? WHERE k = ?')->execute(['2026-06-30', RepoRateRepository::META_VERIFIED_ON]);

        $this->expectException(LateInterestException::class);
        $this->expectExceptionMessage('Číselník repo sazeb ČNB není aktuální');
        $this->creator()->create($this->load($id), self::AS_OF, false, $this->userId);
    }

    // ───────────── VAT handling of the interest invoice ─────────────

    public function testVatAdvisorDoesNotFlagTheInterestDraftOfAVatPayer(): void
    {
        $this->supplier(true);
        $d = $this->load($this->creator()->create($this->load($this->invoice()), self::AS_OF, false, $this->userId)['invoice_id']);
        $advisor = $this->c->get(InvoiceVatAdvisor::class);

        self::assertSame([], $advisor->warnings($this->supplierId, $d));

        // Control: the same all-0 % draft without the classification IS flagged for a payer.
        $control = $d;
        $control['vat_classification_code'] = null;
        foreach ($control['items'] as &$item) {
            $item['vat_classification_code'] = null;
        }
        unset($item);
        self::assertSame('payer_zero_rated', $advisor->warnings($this->supplierId, $control)[0]['code']);
    }

    public function testIssuedInterestInvoiceStaysOutOfVatBooksAndRaisesNoUnclassifiedWarning(): void
    {
        $this->supplier(true);
        $id = $this->creator()->create($this->load($this->invoice()), self::AS_OF, false, $this->userId)['invoice_id'];
        // The owner issues it (we only simulate that): isolated period 2096-05.
        $this->pdo->prepare("UPDATE invoices SET status = 'issued', varsymbol = 'UROK2096', issue_date = '2096-05-10', tax_date = '2096-05-10' WHERE id = ?")->execute([$id]);

        $rows = $this->c->get(VatLedgerService::class)->rows($this->supplierId, '2096-05-01', '2096-05-31');
        $mine = array_values(array_filter($rows, static fn (array $r): bool => $r['doc_number'] === 'UROK2096'));
        self::assertNotEmpty($mine);
        foreach ($mine as $row) {
            self::assertSame('MPD', $row['code']);
            self::assertNull($row['dphdp3_line'], 'no DPHDP3 line -> skipped by the return and the VAT book');
            self::assertNull($row['kh_section']);
        }

        $dph = $this->c->get(DphPriznaniBuilder::class);
        $warnings = implode("\n", $dph->build($this->supplierId, 2096, 5)['warnings']);
        self::assertStringNotContainsString('UROK2096', $warnings);

        // Control: without the classification the 0 % line WOULD raise the unclassified-line warning.
        $this->pdo->prepare('UPDATE invoice_items SET vat_classification_code = NULL WHERE invoice_id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE invoices SET vat_classification_code = NULL WHERE id = ?')->execute([$id]);
        $warnings = implode("\n", $dph->build($this->supplierId, 2096, 5)['warnings']);
        self::assertStringContainsString('UROK2096', $warnings);
    }

    // ───────────── repo-rate codebook ─────────────

    public function testCodebookLookupAtTheBoundaries(): void
    {
        $r = $this->rates();

        self::assertSame(3.50, $r->rateOn('2026-06-18')['rate_percent']);
        self::assertSame(3.75, $r->rateOn('2026-06-19')['rate_percent']);
        self::assertSame(0.05, $r->rateOn('2014-01-01')['rate_percent']);
        self::assertSame(0.05, $r->rateOn('2012-11-02')['rate_percent']);
        self::assertNull($r->rateOn('2012-11-01'));
    }

    public function testCodebookIsAppendOnly(): void
    {
        $r = $this->rates();
        $before = $r->all();
        $latest = $r->latestValidFrom();

        foreach ([$latest, '2025-01-01'] as $notAfterLatest) {
            try {
                $r->append($notAfterLatest, 9.0, 'manual');
                self::fail("a row dated {$notAfterLatest} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('historie se nepřepisuje', $e->getMessage());
            }
        }
        self::assertSame($before, $r->all(), 'refused entries change nothing');

        $r->append('2099-01-01', 4.25, 'manual');
        self::assertSame(4.25, $r->rateOn('2099-06-01')['rate_percent']);
        self::assertSame($before, array_slice($r->all(), 0, count($before)), 'existing history untouched');

        self::assertFalse($r->deleteManual($latest), 'seeded / CNB rows cannot be deleted');
        self::assertSame($latest, $before[count($before) - 1]['valid_from']);
        self::assertTrue($r->deleteManual('2099-01-01'), 'a hand-typed row can be corrected');
        self::assertSame($before, $r->all());

        $this->expectException(\InvalidArgumentException::class);
        $r->append('2099-02-30', 4.0, 'manual');
    }

    public function testVerificationStampOnlyMovesForward(): void
    {
        $r = $this->rates();
        $r->markVerified('2099-01-02');
        $r->markVerified('2020-01-01');

        self::assertSame('2099-01-02', $r->verifiedOn());
    }

    public function testCodebookIsCurrentOnlyForDelaysInHalfYearsStartedBeforeTheVerification(): void
    {
        $r = $this->rates();
        $this->pdo->prepare('UPDATE app_meta SET v = ? WHERE k = ?')->execute(['2026-11-03', RepoRateRepository::META_VERIFIED_ON]);

        self::assertTrue($r->isCurrentFor('2026-07-01'));
        self::assertTrue($r->isCurrentFor('2026-11-03'));
        self::assertFalse($r->isCurrentFor('2027-01-01'), 'the CNB may have changed the rate since');

        $this->pdo->prepare('DELETE FROM app_meta WHERE k = ?')->execute([RepoRateRepository::META_VERIFIED_ON]);
        self::assertFalse($r->isCurrentFor('2020-01-01'), 'never verified');
    }

    public function testCnbFetchAppendsNewRowsOnlyAndNeverRewritesHistory(): void
    {
        $r = $this->rates();
        $before = $r->all();
        $lines = ["PLATNA_OD|CNB_REPO_SAZBA_V_%"];
        foreach ($before as $row) {
            $rate = $row['valid_from'] === '2025-05-09' ? 9.99 : $row['rate_percent']; // CNB "disagrees" about one old row
            $lines[] = str_replace('-', '', $row['valid_from']) . '|' . number_format($rate, 2, ',', '');
        }
        $lines[] = '20990101|4,25';
        $body = "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
        $fetcher = new CnbRepoRateFetcher($r, static fn (): string => $body);

        $result = $fetcher->sync('2099-01-02');

        self::assertSame([['valid_from' => '2099-01-01', 'rate_percent' => 4.25]], $result['added']);
        self::assertSame([['valid_from' => '2025-05-09', 'stored' => 3.5, 'cnb' => 9.99]], $result['conflicts']);
        self::assertSame(3.50, $r->find('2025-05-09')['rate_percent'], 'the conflicting row was NOT overwritten');
        self::assertSame($before, array_slice($r->all(), 0, count($before)));
        self::assertSame('cnb', $r->find('2099-01-01')['source']);
        self::assertSame('2099-01-02', $r->verifiedOn());

        // A second run is a no-op.
        self::assertSame([], $fetcher->sync('2099-01-03')['added']);
    }

    public function testCnbFetchRejectsAnUnexpectedBody(): void
    {
        $fetcher = new CnbRepoRateFetcher($this->rates(), static fn (): string => '<html>503</html>');

        $this->expectException(\RuntimeException::class);
        $fetcher->sync('2099-01-02');
    }

    // ───────────── HTTP actions ─────────────

    private function request(string $method, array $body = [], array $query = [], string $role = 'admin'): \Psr\Http\Message\ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest($method, '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withQueryParams($query)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testInvoiceActionOverrideRoundTripAndCreation(): void
    {
        $action = $this->c->get(LateInterestAction::class);
        $id = $this->invoice(['varsymbol' => 'FA-ACT']);

        $get = $action->get($this->request('GET', [], ['as_of' => self::AS_OF]), new Response(), ['id' => $id]);
        self::assertSame(200, $get->getStatusCode());
        $payload = $this->json($get);
        self::assertFalse($payload['enabled']);
        self::assertNull($payload['summary']);
        self::assertNull($payload['invoice_override']);

        // opt in this single invoice, then back to inherit
        $put = $action->setOverride($this->request('PUT', ['late_interest' => true]), new Response(), ['id' => $id]);
        self::assertTrue($this->json($put)['enabled']);
        self::assertTrue($this->load($id)['late_interest']);
        $this->json($action->setOverride($this->request('PUT', ['late_interest' => null]), new Response(), ['id' => $id]));
        self::assertNull($this->load($id)['late_interest']);
        $bad = $action->setOverride($this->request('PUT', ['late_interest' => 'yes']), new Response(), ['id' => $id]);
        self::assertSame(422, $bad->getStatusCode());

        // billing refused while the feature is off, accepted once enabled
        $refused = $action->createInvoice($this->request('POST', ['as_of' => self::AS_OF]), new Response(), ['id' => $id]);
        self::assertSame(409, $refused->getStatusCode());
        $future = $action->createInvoice($this->request('POST', ['as_of' => '2999-01-01']), new Response(), ['id' => $id]);
        self::assertSame(422, $future->getStatusCode());

        $this->supplier(true);
        $created = $action->createInvoice($this->request('POST', ['as_of' => self::AS_OF, 'include_fixed_cost' => false]), new Response(), ['id' => $id]);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('draft', $this->json($created)['invoice']['status']);
    }

    public function testInvoiceActionHidesInvoicesOfAnotherSupplier(): void
    {
        $id = $this->invoice();
        $request = $this->request('GET')->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 999);

        $res = $this->c->get(LateInterestAction::class)->get($request, new Response(), ['id' => $id]);

        self::assertSame(404, $res->getStatusCode());
    }

    public function testRepoRateActionIsAdminOnlyForWrites(): void
    {
        $action = $this->c->get(RepoRatesAction::class);

        self::assertSame(200, $action->list($this->request('GET', [], [], 'readonly'), new Response())->getStatusCode());
        $denied = $action->create($this->request('POST', ['valid_from' => '2099-01-01', 'rate_percent' => 4.0], [], 'accountant'), new Response());
        self::assertSame(403, $denied->getStatusCode());
        self::assertNull($this->rates()->find('2099-01-01'));

        $ok = $action->create($this->request('POST', ['valid_from' => '2099-01-01', 'rate_percent' => 4.0]), new Response());
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('2099-01-01', $this->json($ok)['rates'][0]['valid_from'], 'newest first');
        self::assertTrue($this->json($ok)['is_current'], 'a hand-typed rate means the admin has just checked the CNB');

        $old = $action->create($this->request('POST', ['valid_from' => '2020-01-01', 'rate_percent' => 4.0]), new Response());
        self::assertSame(422, $old->getStatusCode());

        $del = $action->delete($this->request('DELETE'), new Response(), ['date' => '2026-06-19']);
        self::assertSame(404, $del->getStatusCode(), 'non-manual rows are immutable');
        $del = $action->delete($this->request('DELETE'), new Response(), ['date' => '2099-01-01']);
        self::assertSame(200, $del->getStatusCode());
    }
}
