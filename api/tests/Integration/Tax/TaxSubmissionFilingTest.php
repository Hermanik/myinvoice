<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Report\TaxSubmissionAction;
use MyInvoice\Action\Report\TaxSubmissionFilingAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;
use Slim\Psr7\Response;

/**
 * Filing lifecycle of the submission archive: generated -> filed -> confirmed, the duplicate guard
 * (one filed/confirmed submission per supplier + type + period + form), the receipt (stored with
 * size + sha256, downloadable) and the admin-only HTTP surface.
 *
 * Works in the year 2099 only; every created row is removed again in tearDown.
 */
#[Group('integration')]
final class TaxSubmissionFilingTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private TaxSubmissionFilingService $filing;
    private TaxSubmissionRepository $repo;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->filing = $this->fx->container->get(TaxSubmissionFilingService::class);
        $this->repo = $this->fx->container->get(TaxSubmissionRepository::class);
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    private function sid(): int
    {
        return $this->fx->supplierId;
    }

    public function testStateMachineGeneratedFiledConfirmedAndBack(): void
    {
        $id = $this->fx->submission('dphdp3', 2099, 3, null);
        self::assertSame('generated', $this->repo->findMeta($id, $this->sid())['filing_status']);

        $filed = $this->filing->markFiled($this->sid(), $id, '2020-04-20 10:30', 'epo', ' 123456789 ', $this->fx->userId);
        self::assertSame('filed', $filed['filing_status']);
        self::assertSame('2020-04-20 10:30:00', $filed['filed_at']);
        self::assertSame('epo', $filed['filing_channel']);
        self::assertSame('123456789', $filed['filing_reference'], 'the reference is trimmed');
        self::assertFalse($filed['has_receipt']);

        $confirmed = $this->filing->attachReceipt($this->sid(), $id, "%PDF-1.4\nreceipt", 'potvrzeni.pdf', $this->fx->userId);
        self::assertSame('confirmed', $confirmed['filing_status']);
        self::assertTrue($confirmed['has_receipt']);
        self::assertNotNull($confirmed['confirmed_at']);

        $dropped = $this->filing->removeReceipt($this->sid(), $id, $this->fx->userId);
        self::assertSame('filed', $dropped['filing_status']);
        self::assertFalse($dropped['has_receipt']);

        $reverted = $this->filing->revertFiling($this->sid(), $id, $this->fx->userId);
        self::assertSame('generated', $reverted['filing_status']);
        self::assertNull($reverted['filed_at']);
        self::assertNull($reverted['filing_channel']);
    }

    public function testInvalidTransitionsAreRefused(): void
    {
        $id = $this->fx->submission('dphkh1', 2099, 3, null);

        $this->assertFilingError('not_filed', fn () => $this->filing->attachReceipt($this->sid(), $id, "%PDF-1.4\nx", 'a.pdf', null));
        $this->assertFilingError('not_filed', fn () => $this->filing->revertFiling($this->sid(), $id, null));
        $this->assertFilingError('no_receipt', fn () => $this->filing->removeReceipt($this->sid(), $id, null));
        $this->assertFilingError('validation_failed', fn () => $this->filing->markFiled($this->sid(), $id, null, 'fax', null, null));

        $this->filing->markFiled($this->sid(), $id, null, 'data_box', null, null);
        $this->assertFilingError('already_filed', fn () => $this->filing->markFiled($this->sid(), $id, null, 'epo', null, null));
        $this->assertFilingError('not_found', fn () => $this->filing->markFiled($this->sid(), 999999999, null, 'epo', null, null));
    }

    public function testFilingDateCannotBeInTheFutureAndDefaultsToNow(): void
    {
        $id = $this->fx->submission('dphkh1', 2099, 4, null);
        $this->assertFilingError('validation_failed', fn () => $this->filing->markFiled($this->sid(), $id, '2999-01-01 10:00', 'epo', null, null));

        $before = new \DateTimeImmutable();
        $row = $this->filing->markFiled($this->sid(), $id, null, 'other', null, null);
        self::assertEqualsWithDelta($before->getTimestamp(), (new \DateTimeImmutable($row['filed_at']))->getTimestamp(), 5);
    }

    public function testOnlyOneSubmissionPerTypePeriodAndFormCanBeFiled(): void
    {
        $first = $this->fx->submission('dphdp3', 2099, 5, null, 'B');
        $again = $this->fx->submission('dphdp3', 2099, 5, null, 'B');       // regenerated XML of the same return
        $corrective = $this->fx->submission('dphdp3', 2099, 5, null, 'O');  // other form -> its own slot
        $otherPeriod = $this->fx->submission('dphdp3', 2099, 6, null, 'B');
        $otherType = $this->fx->submission('dphkh1', 2099, 5, null, 'B');

        $this->filing->markFiled($this->sid(), $first, null, 'epo', null, null);

        try {
            $this->filing->markFiled($this->sid(), $again, null, 'epo', null, null);
            self::fail('A second filing of the same type + period + form must be refused.');
        } catch (TaxFilingException $e) {
            self::assertSame('duplicate_filing', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertSame($first, $e->extra['conflict']['id']);
        }

        foreach ([$corrective, $otherPeriod, $otherType] as $id) {
            self::assertSame('filed', $this->filing->markFiled($this->sid(), $id, null, 'epo', null, null)['filing_status']);
        }

        // Reverting the first one frees the slot.
        $this->filing->revertFiling($this->sid(), $first, null);
        self::assertSame('filed', $this->filing->markFiled($this->sid(), $again, null, 'epo', null, null)['filing_status']);
    }

    public function testASecondReturnOfThePeriodNeedsAFullFilingTime(): void
    {
        $first = $this->fx->submission('dphdp3', 2099, 7, null, 'B');
        $corrective = $this->fx->submission('dphdp3', 2099, 7, null, 'O');

        // Nothing filed yet for the period: a bare date is the start of that day.
        $row = $this->filing->markFiled($this->sid(), $first, '2020-08-10', 'epo', null, null);
        self::assertSame('2020-08-10 00:00:00', $row['filed_at']);

        // Another return is in the books now, so the order of the two decides which one is in force.
        $this->assertFilingError('time_required', fn () => $this->filing->markFiled($this->sid(), $corrective, '2020-08-12', 'epo', null, null));
        self::assertSame('generated', $this->repo->findMeta($corrective, $this->sid())['filing_status'], 'a refused filing changes nothing');

        $this->filing->markFiled($this->sid(), $corrective, '2020-08-12 09:15', 'epo', null, null);
        self::assertSame([$first, $corrective], array_column($this->repo->filedForPeriod($this->sid(), 'dphdp3', 2099, 7, null), 'id'));
    }

    public function testTheReturnInForceIsTheLastOneByFilingTimeThenById(): void
    {
        $early = $this->fx->submission('dphdp3', 2099, 8, null, 'B');   // lower id, filed LATER
        $late = $this->fx->submission('dphdp3', 2099, 8, null, 'O');
        $this->filing->markFiled($this->sid(), $early, '2020-09-05 12:00', 'epo', null, null);
        $this->filing->markFiled($this->sid(), $late, '2020-09-05 08:00', 'epo', null, null);

        $order = static fn (array $rows): array => array_column($rows, 'id');
        self::assertSame([$late, $early], $order($this->repo->filedForPeriod($this->sid(), 'dphdp3', 2099, 8, null)), 'filing time wins over the id');
        // The same minute: the later-created row is the one in force.
        $a = $this->fx->submission('dphdp3', 2099, 9, null, 'B');
        $b = $this->fx->submission('dphdp3', 2099, 9, null, 'O');
        $this->filing->markFiled($this->sid(), $b, '2020-10-05 10:00', 'epo', null, null);
        $this->filing->markFiled($this->sid(), $a, '2020-10-05 10:00', 'epo', null, null);
        self::assertSame([$a, $b], $order($this->repo->filedForPeriod($this->sid(), 'dphdp3', 2099, 9, null)));

        // The per-supplier feed (calendar, payment card) keeps the same order within each period.
        $byTypes = array_values(array_filter($this->repo->filedByTypes($this->sid(), ['dphdp3'], false), fn ($r) => $r['period_year'] === 2099 && in_array($r['period_month'], [8, 9], true)));
        self::assertSame([$late, $early, $a, $b], $order($byTypes));
    }

    public function testDatabaseGuardRejectsADuplicateEvenWithoutTheServiceCheck(): void
    {
        $a = $this->fx->submission('dphshv', 2099, null, 2, 'R');
        $b = $this->fx->submission('dphshv', 2099, null, 2, 'R');
        $this->repo->markFiled($a, '2099-07-10 08:00:00', 'epo', null, null);

        $this->expectException(\PDOException::class);
        $this->repo->markFiled($b, '2099-07-11 08:00:00', 'epo', null, null); // concurrent request that passed the PHP check
    }

    public function testReceiptIsStoredWithHashAndSizeAndCanBeReadBack(): void
    {
        $id = $this->fx->submission('dphdp3', 2099, 7, null);
        $this->filing->markFiled($this->sid(), $id, null, 'epo', null, null);
        $content = "\x30\x82\x01\x00" . random_bytes(64); // DER SEQUENCE like a PKCS#7 file

        $row = $this->filing->attachReceipt($this->sid(), $id, $content, '../../potvrzení podání.p7s', $this->fx->userId);

        self::assertSame(strlen($content), $row['receipt_size_bytes']);
        self::assertSame(hash('sha256', $content), $row['receipt_sha256']);
        self::assertSame('potvrzení podání.p7s', $row['receipt_name'], 'path segments are stripped');
        $receipt = $this->repo->receipt($id, $this->sid());
        self::assertSame($content, $receipt['content']);
        self::assertSame('application/pkcs7-signature', $receipt['mime']);
        // The receipt blob never travels with the list / detail payloads.
        self::assertArrayNotHasKey('receipt_content', $this->repo->find($id, $this->sid()));
        self::assertArrayNotHasKey('receipt_content', $this->repo->list($this->sid())[0]);
        // Replacing the receipt of a confirmed submission keeps it confirmed.
        $replaced = $this->filing->attachReceipt($this->sid(), $id, "%PDF-1.7\nnew", 'nove.pdf', $this->fx->userId);
        self::assertSame('confirmed', $replaced['filing_status']);
        self::assertSame('nove.pdf', $replaced['receipt_name']);
    }

    public function testReceiptValidation(): void
    {
        $id = $this->fx->submission('dphdp3', 2099, 8, null);
        $this->filing->markFiled($this->sid(), $id, null, 'epo', null, null);

        $this->assertFilingError('invalid_extension', fn () => $this->filing->attachReceipt($this->sid(), $id, 'x', 'potvrzeni.exe', null));
        $this->assertFilingError('invalid_pdf', fn () => $this->filing->attachReceipt($this->sid(), $id, '<html>', 'potvrzeni.pdf', null));
        $this->assertFilingError('invalid_receipt', fn () => $this->filing->attachReceipt($this->sid(), $id, '<html><script>alert(1)</script>', 'potvrzeni.p7s', null));
        $this->assertFilingError('empty_file', fn () => $this->filing->attachReceipt($this->sid(), $id, '', 'potvrzeni.pdf', null));
        $this->assertFilingError('file_too_large', fn () => $this->filing->attachReceipt($this->sid(), $id, "%PDF-1.4\n" . str_repeat('a', TaxSubmissionFilingService::RECEIPT_MAX_BYTES), 'potvrzeni.pdf', null));
        self::assertSame('filed', $this->repo->findMeta($id, $this->sid())['filing_status'], 'a refused upload changes nothing');
    }

    public function testFiledSubmissionCannotBeDeletedUntilTheFilingIsReverted(): void
    {
        $id = $this->fx->submission('dphdp3', 2099, 9, null);
        $this->filing->markFiled($this->sid(), $id, null, 'epo', null, null);
        $action = $this->fx->container->get(TaxSubmissionAction::class);

        $refused = $action->delete($this->request('DELETE', 'admin'), new Response(), ['id' => $id]);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('filed_submission', $this->json($refused)['error']['code']);

        $this->filing->revertFiling($this->sid(), $id, null);
        $deleted = $action->delete($this->request('DELETE', 'admin'), new Response(), ['id' => $id]);
        self::assertSame(200, $deleted->getStatusCode());
    }

    public function testHttpWritesAreAdminOnlyAndReceiptRoundTrips(): void
    {
        $id = $this->fx->submission('dphkh1', 2099, 10, null, 'B');
        $action = $this->fx->container->get(TaxSubmissionFilingAction::class);

        $denied = $action->markFiled($this->request('POST', 'accountant', ['channel' => 'epo']), new Response(), ['id' => $id]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('generated', $this->repo->findMeta($id, $this->sid())['filing_status']);

        $ok = $action->markFiled($this->request('POST', 'admin', ['channel' => 'epo', 'reference' => 'KH-1', 'filed_at' => '2020-11-02 09:15']), new Response(), ['id' => $id]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('filed', $this->json($ok)['filing_status']);

        $dup = $this->fx->submission('dphkh1', 2099, 10, null, 'B');
        $conflict = $action->markFiled($this->request('POST', 'admin', ['channel' => 'epo']), new Response(), ['id' => $dup]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('duplicate_filing', $this->json($conflict)['error']['code']);

        $content = "%PDF-1.4\nHTTP receipt";
        $upload = (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, 'potvrzeni.pdf');
        $uploaded = $action->uploadReceipt($this->request('POST', 'admin')->withUploadedFiles(['file' => $upload]), new Response(), ['id' => $id]);
        self::assertSame(200, $uploaded->getStatusCode());
        self::assertSame('confirmed', $this->json($uploaded)['filing_status']);

        $download = $action->downloadReceipt($this->request('GET', 'readonly'), new Response(), ['id' => $id]);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame($content, (string) $download->getBody());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $download->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));

        $noFile = $action->uploadReceipt($this->request('POST', 'admin'), new Response(), ['id' => $id]);
        self::assertSame(400, $noFile->getStatusCode());
        $missing = $action->downloadReceipt($this->request('GET', 'admin'), new Response(), ['id' => $dup]);
        self::assertSame(404, $missing->getStatusCode());
    }

    public function testTheArchiverStoresTheFilingFormOfTheSummary(): void
    {
        $archiver = $this->fx->container->get(\MyInvoice\Service\Report\TaxSubmissionArchiver::class);
        $archive = function (string $formCode, array $summary) use ($archiver): string {
            $id = $archiver->archive($this->sid(), $formCode, 2099, 2, null, '<x/>', $summary, $this->fx->userId)['submission_id'];
            $this->fx->trackSubmission($id);
            return $this->repo->findMeta($id, $this->sid())['submission_form'];
        };

        self::assertSame('O', $archive('dphdp3', ['form' => 'O']), 'corrective DPH return');
        self::assertSame('N', $archive('dphkh1', ['form' => 'n']), 'subsequent KH, upper-cased');
        self::assertSame('R', $archive('dphshv', ['form' => 'R']), 'regular SH');
        self::assertSame('B', $archive('dpfdp5', ['year' => 2098]), 'no form in the summary -> regular');
    }

    public function testActivityLogRecordsTheLifecycle(): void
    {
        $id = $this->fx->submission('dphdp3', 2099, 11, null);
        $this->filing->markFiled($this->sid(), $id, null, 'epo', 'REF', $this->fx->userId);
        $this->filing->attachReceipt($this->sid(), $id, "%PDF-1.4\nx", 'a.pdf', $this->fx->userId);
        $this->filing->revertFiling($this->sid(), $id, $this->fx->userId);

        $stmt = $this->fx->pdo->prepare("SELECT action FROM activity_log WHERE entity_type = 'tax_submission' AND entity_id = ? ORDER BY id");
        $stmt->execute([$id]);
        self::assertSame(
            ['tax_submission.filed', 'tax_submission.receipt_uploaded', 'tax_submission.filing_reverted'],
            $stmt->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    private function assertFilingError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail("Expected TaxFilingException {$code}");
        } catch (TaxFilingException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function request(string $method, string $role, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/reports/submissions/1')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => $role]);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
