<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeAlert;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNotice;

/** Stands in for the alert mailer: records what would have been sent, or fails on demand. */
final class RecordingEmailNoticeAlert extends DataboxEmailNoticeAlert
{
    /** @var list<array{to:string, box_id:int, dm_id:string, message_id:int, is_tax_office:bool, arrived_at:string}> */
    public array $sent = [];
    public ?\Throwable $failWith = null;

    public function __construct() {}

    public function send(string $to, array $box, DatovkaNotice $notice, int $messageId, \DateTimeImmutable $arrivedAt, bool $isTaxOffice): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = [
            'to' => $to, 'box_id' => (int) $box['id'], 'dm_id' => $notice->dmId, 'message_id' => $messageId,
            'is_tax_office' => $isTaxOffice, 'arrived_at' => $arrivedAt->format('Y-m-d H:i:s'),
        ];
    }
}
