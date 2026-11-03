<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `reset.php --keep-users-supplier` keeps the supplier row; without its dated VAT status history the
 * kept supplier would silently fall back to the cached flags (losing a scheduled registration date).
 */
final class ResetKeepsVatStatusHistoryTest extends TestCase
{
    public function testKeepUsersSupplierModeKeepsTheVatStatusHistory(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/reset.php');

        $keepBlock = substr($source, (int) strpos($source, 'if ($keepUsersSupplier) {'));
        self::assertStringContainsString("'supplier_vat_status_history'", $keepBlock);
        self::assertLessThan(
            (int) strpos($keepBlock, 'unset($partial'),
            (int) strpos($keepBlock, "'supplier_vat_status_history'"),
            'the history must be in the keep list of the --keep-users-supplier branch',
        );
    }
}
