<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

final readonly class DkimResult
{
    /**
     * @param string $detail Stable machine-readable code, `dkim_local_pass:<d>` on success.
     * @param bool $retry True when the failure is temporary (DNS lookup error) and the
     *                    message should be looked at again on the next scan.
     */
    public function __construct(
        public bool $pass,
        public string $detail,
        public bool $retry = false,
    ) {}
}
