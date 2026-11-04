<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

enum AuthVerdict
{
    case Pass;
    case Fail;
    /** Temporary failure (DNS): no verdict yet, look at the message again on the next scan. */
    case Deferred;
}
