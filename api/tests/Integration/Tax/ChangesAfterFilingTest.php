<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Tax\ChangesAfterFilingService;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "Doklady změněné po podání": documents with their DUZP in the period of a filed return that were
 * created, issued, edited, cancelled or deleted after the return was filed.
 */
#[Group('integration')]
final class ChangesAfterFilingTest extends TestCase
{
    private const FILED_AT = '2099-07-01 12:00:00';

    private ?FilingFixture $fx = null;
    private ChangesAfterFilingService $changes;
    private int $client = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->changes = $this->fx->container->get(ChangesAfterFilingService::class);
        $this->fx->payer();
        $this->client = $this->fx->client('Změny po podání', null, 'CZ11111118');
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    /** @return array<string, array<string,mixed>> items keyed by "<source>:<doc number>" */
    private function listed(): array
    {
        $out = [];
        foreach ($this->changes->changes($this->fx->supplierId, self::FILED_AT, '2099-06-01', '2099-06-30') as $item) {
            $out[$item['source'] . ':' . $item['doc_number']] = $item;
        }
        return $out;
    }

    /** @param array<string,mixed> $item @return list<string> */
    private static function types(array $item): array
    {
        return array_column($item['changes'], 'type');
    }

    public function testListsDocumentsCreatedIssuedEditedCancelledOrDeletedAfterFiling(): void
    {
        $fx = $this->fx;
        $before = '2099-06-20 10:00:00';
        // untouched, or touched only before filing -> not listed
        $fx->sale('S-UNTOUCHED', $this->client, '2099-06-10', 1000, 210, 21, '1', 'issued', $before);
        $edited = $fx->sale('S-EDITED-BEFORE', $this->client, '2099-06-11', 1000, 210, 21, '1', 'issued', $before);
        $fx->log('invoice.updated', 'invoice', $edited, '2099-06-25 09:00:00');
        // after filing -> listed
        $fx->sale('S-CREATED', $this->client, '2099-06-12', 2000, 420, 21, '1', 'issued', '2099-07-05 09:00:00');
        $forceEdited = $fx->sale('S-FORCE-EDIT', $this->client, '2099-06-13', 3000, 630, 21, '1', 'paid', $before);
        $fx->log('invoice.force_edit', 'invoice', $forceEdited, '2099-07-03 08:30:00');
        $fx->log('invoice.sent', 'invoice', $forceEdited, '2099-07-03 09:00:00'); // sending changes nothing in the return
        $cancelled = $fx->sale('S-CANCELLED', $this->client, '2099-06-14', 4000, 840, 21, '1', 'cancelled', $before);
        $fx->pdo->exec("UPDATE invoices SET cancelled_at = '2099-07-04 11:00:00' WHERE id = {$cancelled}");
        $issuedLater = $fx->sale('S-ISSUED-LATER', $this->client, '2099-06-15', 5000, 1050, 21, '1', 'issued', $before);
        $fx->log('invoice.issued', 'invoice', $issuedLater, '2099-07-02 10:00:00');
        // not in the period / not a return document -> not listed
        $fx->sale('S-OTHER-PERIOD', $this->client, '2099-07-02', 1000, 210, 21, '1', 'issued', '2099-07-05 09:00:00');
        $fx->sale('S-DRAFT', $this->client, '2099-06-16', 1000, 210, 21, '1', 'draft', '2099-07-05 09:00:00');
        // purchases
        $fx->purchase('P-CREATED', $this->client, '2099-06-17', 1000, 210, 21, '40', 'received', '2099-07-06 09:00:00');
        $purchaseEdited = $fx->purchase('P-EDITED', $this->client, '2099-06-18', 1000, 210, 21, '40', 'received', $before);
        $fx->log('purchase_invoice.updated', 'purchase_invoice', $purchaseEdited, '2099-07-07 09:00:00');
        $fx->purchase('P-DRAFT', $this->client, '2099-06-19', 1000, 210, 21, '40', 'draft', '2099-07-06 09:00:00');
        // deleted documents (the log payload carries the DUZP)
        $fx->log('invoice.force_deleted', 'invoice', 900000001, '2099-07-08 10:00:00', ['varsymbol' => 'S-DELETED', 'type' => 'invoice', 'status_before' => 'issued', 'tax_date' => '2099-06-20', 'issue_date' => '2099-06-20', 'total' => 1210]);
        $fx->log('invoice.force_deleted', 'invoice', 900000002, '2099-07-08 10:05:00', ['varsymbol' => 'S-DELETED-OTHER-PERIOD', 'type' => 'invoice', 'status_before' => 'issued', 'tax_date' => '2099-08-20']);
        $fx->log('invoice.force_deleted', 'invoice', 900000003, '2099-07-08 10:10:00', ['varsymbol' => 'S-DELETED-PROFORMA', 'type' => 'proforma', 'status_before' => 'issued', 'tax_date' => '2099-06-20']);
        $fx->log('purchase_invoice.deleted', 'purchase_invoice', 900000004, '2099-07-08 10:15:00', ['varsymbol' => 'P-DELETED', 'status_before' => 'received', 'tax_date' => '2099-06-21', 'issue_date' => '2099-06-21']);
        $fx->log('purchase_invoice.deleted', 'purchase_invoice', 900000005, '2099-07-08 10:20:00', ['varsymbol' => 'P-DELETED-DRAFT', 'status_before' => 'draft', 'tax_date' => '2099-06-21']);
        $fx->log('invoice.force_deleted', 'invoice', 900000006, '2099-06-30 10:00:00', ['varsymbol' => 'S-DELETED-BEFORE', 'type' => 'invoice', 'status_before' => 'issued', 'tax_date' => '2099-06-20']);

        $listed = $this->listed();

        self::assertEqualsCanonicalizing([
            'sale:S-CREATED', 'sale:S-FORCE-EDIT', 'sale:S-CANCELLED', 'sale:S-ISSUED-LATER', 'sale:S-DELETED',
            'purchase:P-CREATED', 'purchase:P-EDITED', 'purchase:P-DELETED',
        ], array_keys($listed));
        self::assertSame(['created'], self::types($listed['sale:S-CREATED']));
        self::assertSame(['edited'], self::types($listed['sale:S-FORCE-EDIT']), 'sending the invoice is not a change of the return');
        self::assertSame(['cancelled'], self::types($listed['sale:S-CANCELLED']));
        self::assertSame(['issued'], self::types($listed['sale:S-ISSUED-LATER']));
        self::assertSame(['deleted'], self::types($listed['sale:S-DELETED']));
        self::assertTrue($listed['sale:S-DELETED']['deleted']);
        self::assertSame(1210.0, $listed['sale:S-DELETED']['total_with_vat']);
        self::assertSame('2099-07-03 08:30:00', $listed['sale:S-FORCE-EDIT']['changes'][0]['at']);
        self::assertSame(['created'], self::types($listed['purchase:P-CREATED']));
        self::assertSame(['edited'], self::types($listed['purchase:P-EDITED']));
        self::assertSame(['deleted'], self::types($listed['purchase:P-DELETED']));
    }

    public function testAnEditBeforeAndAfterFilingReportsOnlyTheLaterOne(): void
    {
        $id = $this->fx->sale('S-TWICE', $this->client, '2099-06-10', 1000, 210, 21, '1', 'issued', '2099-06-01 10:00:00');
        $this->fx->log('invoice.updated', 'invoice', $id, '2099-06-15 10:00:00');
        $this->fx->log('invoice.force_edit', 'invoice', $id, '2099-07-09 10:00:00');
        $this->fx->log('invoice.force_edit', 'invoice', $id, '2099-07-10 10:00:00');

        $item = $this->listed()['sale:S-TWICE'];

        self::assertSame(['edited', 'edited'], self::types($item));
        self::assertSame(['2099-07-09 10:00:00', '2099-07-10 10:00:00'], array_column($item['changes'], 'at'));
    }

    public function testForSubmissionUsesTheFilingTimeAndThePeriodOfTheReturn(): void
    {
        $repo = $this->fx->container->get(TaxSubmissionRepository::class);
        $filing = $this->fx->container->get(TaxSubmissionFilingService::class);
        $this->fx->sale('S-CREATED-LATE', $this->client, '2099-06-12', 2000, 420, 21, '1', 'issued', '2099-07-05 09:00:00');
        $id = $this->fx->dphSubmission(2099, 6, null, 1000);

        // Not filed yet: nothing to compare against.
        self::assertSame(0, $this->changes->forSubmission($repo->findMeta($id, $this->fx->supplierId))['count']);

        $filing->markFiled($this->fx->supplierId, $id, '2020-01-01 10:00', 'epo', null, null);
        $result = $this->changes->forSubmission($repo->findMeta($id, $this->fx->supplierId));
        self::assertSame(1, $result['count']);
        self::assertSame('2099-06-01', $result['period_start']);
        self::assertSame('2099-06-30', $result['period_end']);
        self::assertSame('S-CREATED-LATE', $result['items'][0]['doc_number']);

        // The same document is an "after filing" change for a return filed earlier, but not for one filed later.
        $this->fx->pdo->prepare("UPDATE tax_submissions SET filed_at = '2099-07-10 00:00:00' WHERE id = ?")->execute([$id]);
        self::assertSame(0, $this->changes->forSubmission($repo->findMeta($id, $this->fx->supplierId))['count']);

        // Income tax and OSS returns are not built from the VAT ledger.
        $dpfo = $this->fx->submission('dpfdp5', 2098, null, null);
        $this->fx->pdo->prepare("UPDATE tax_submissions SET filing_status = 'filed', filed_at = '2020-01-01 00:00:00' WHERE id = ?")->execute([$dpfo]);
        self::assertSame(0, $this->changes->forSubmission($repo->findMeta($dpfo, $this->fx->supplierId))['count']);
    }

    public function testQuarterlyReturnCoversTheWholeQuarter(): void
    {
        $repo = $this->fx->container->get(TaxSubmissionRepository::class);
        $this->fx->sale('S-APRIL', $this->client, '2099-04-12', 1000, 210, 21, '1', 'issued', '2099-07-05 09:00:00');
        $this->fx->sale('S-JULY', $this->client, '2099-07-12', 1000, 210, 21, '1', 'issued', '2099-07-05 09:00:00');
        $id = $this->fx->submission('dphkh1', 2099, null, 2);
        $this->fx->pdo->prepare("UPDATE tax_submissions SET filing_status = 'filed', filed_at = '2099-07-01 12:00:00' WHERE id = ?")->execute([$id]);

        $result = $this->changes->forSubmission($repo->findMeta($id, $this->fx->supplierId));

        self::assertSame(['S-APRIL'], array_column($result['items'], 'doc_number'));
    }
}
