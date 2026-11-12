<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

/**
 * How the bulk export assigns invoices to its period.
 *
 *  - Duzp: VAT-style (issued by DUZP, received by the later of DUZP / issue date). Matches the
 *    VAT book and the VAT return.
 *  - Payment: by the date of full payment. A sole trader's income tax counts income and expenses
 *    when they are paid, so a December invoice paid in January belongs to the next year.
 */
enum ExportBasis: string
{
    case Duzp = 'duzp';
    case Payment = 'payment';

    public static function defaultFor(ExportPeriod $period): self
    {
        return $period->isYearly() ? self::Payment : self::Duzp;
    }
}
