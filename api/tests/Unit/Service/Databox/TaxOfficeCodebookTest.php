<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\DataboxId;
use MyInvoice\Service\Databox\TaxOfficeCodebook;
use PHPUnit\Framework\TestCase;

final class TaxOfficeCodebookTest extends TestCase
{
    public function testEveryBoxIdPassesTheOfficialCheckDigit(): void
    {
        foreach (TaxOfficeCodebook::all() as $cUfo => $office) {
            self::assertTrue(DataboxId::isValid($office['box_id']), "c_ufo {$cUfo} ({$office['name']}): {$office['box_id']} fails the Luhn mod 32 check");
        }
        self::assertTrue(DataboxId::isValid('p9iwj4f'), 'GFR box');
    }

    public function testCoversAllRegionalOfficesAndTheSpecialisedOffice(): void
    {
        $all = TaxOfficeCodebook::all();
        self::assertCount(15, $all);
        foreach (range(451, 464) as $code) {
            self::assertArrayHasKey((string) $code, $all);
            self::assertStringStartsWith('Finanční úřad pro ', $all[(string) $code]['name']);
        }
        self::assertSame('Specializovaný finanční úřad', $all['13']['name']);
        self::assertCount(15, array_unique(array_column($all, 'box_id')), 'no box ID is shared by two offices');
    }

    public function testLookupByTheCUfoTheApplicationStores(): void
    {
        self::assertSame('7nyn2d9', TaxOfficeCodebook::forUfo('451')['box_id'] ?? null);
        self::assertSame('qdhny4c', TaxOfficeCodebook::forUfo('461')['box_id'] ?? null);
        self::assertSame('7cs8cge', TaxOfficeCodebook::forUfo('13')['box_id'] ?? null);
        self::assertSame('7cs8cge', TaxOfficeCodebook::forUfo('013')['box_id'] ?? null, 'leading zeros are tolerated');
        self::assertSame('Finanční úřad pro Jihomoravský kraj', TaxOfficeCodebook::forUfo(' 461 ')['name']);
    }

    public function testUnknownCodesGiveNoRecipient(): void
    {
        self::assertNull(TaxOfficeCodebook::forUfo('999')['box_id'] ?? null);
        self::assertNull(TaxOfficeCodebook::forUfo('')['box_id'] ?? null);
        self::assertNull(TaxOfficeCodebook::forUfo(null)['box_id'] ?? null);
        self::assertNull(TaxOfficeCodebook::forUfo('551'), 'the pre-2013 Financni reditelstvi is gone');
    }

    public function testRecognisesTaxOfficeBoxesIncludingTheGeneralDirectorate(): void
    {
        self::assertTrue(TaxOfficeCodebook::isTaxOfficeBox('7nyn2d9'));
        self::assertTrue(TaxOfficeCodebook::isTaxOfficeBox('7NYN2D9'));
        self::assertTrue(TaxOfficeCodebook::isTaxOfficeBox('p9iwj4f'));
        self::assertFalse(TaxOfficeCodebook::isTaxOfficeBox('tpbxbx7'));
        self::assertFalse(TaxOfficeCodebook::isTaxOfficeBox(null));
    }
}
