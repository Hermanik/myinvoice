<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

/**
 * The facts of one "new data message" notification e-mail of the data box system (ISDS).
 */
final readonly class DatovkaNotice
{
    public const KIND_DATA = 'data';
    public const KIND_SYSTEM = 'system';

    /**
     * @param self::KIND_* $kind data message from another box, or a system message issued by ISDS itself
     * @param ?\DateTimeImmutable $noticedAt Date header of the e-mail, i.e. when ISDS announced the message
     */
    public function __construct(
        public string $kind,
        public ?string $annotation,
        public ?string $senderName,
        public string $senderBoxId,
        public ?string $recipientName,
        public string $recipientBoxId,
        public string $dmId,
        public ?\DateTimeImmutable $noticedAt,
    ) {}
}
