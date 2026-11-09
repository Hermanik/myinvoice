<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\Zfo;

/**
 * What an uploaded, verified ZFO says: a signed data message or a signed delivery receipt of one.
 */
final class ZfoDocument
{
    public const MESSAGE = 'message';
    public const RECEIPT = 'receipt';

    /**
     * @param self::MESSAGE|self::RECEIPT $kind
     * @param array<string,mixed> $envelope fields as {@see \MyInvoice\Service\Databox\IsdsResponseParser::envelopeFields()} returns them
     * @param list<array{size:int, sha256:string, epo:bool}> $attachments message only; `epo` = looks like an EPO filing XML
     * @param ?string $deliveryInfoXml receipt only: the unsigned form of the receipt, as the delivery info is stored
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $dmId,
        public readonly array $envelope,
        public readonly ?int $status,
        public readonly ?\DateTimeImmutable $deliveryTime,
        public readonly ?\DateTimeImmutable $acceptanceTime,
        public readonly ?int $attachmentSizeKb,
        public readonly array $attachments,
        public readonly ?string $deliveryInfoXml,
    ) {}

    /** The kind of the stored file this document is: the sealed message or the sealed receipt. @return 'zfo'|'delivery_zfo' */
    public function fileKind(): string
    {
        return $this->kind === self::MESSAGE ? 'zfo' : 'delivery_zfo';
    }

    public function senderBoxId(): ?string
    {
        return $this->envelope['sender_box_id'] ?? null;
    }

    public function recipientBoxId(): ?string
    {
        return $this->envelope['recipient_box_id'] ?? null;
    }

    /** The envelope with the lifecycle data, in the shape the message repository stores. @return array<string,mixed> */
    public function toEnvelope(): array
    {
        return [
            'status' => $this->status,
            'delivery_time' => $this->deliveryTime,
            'acceptance_time' => $this->acceptanceTime,
            'attachment_size_kb' => $this->attachmentSizeKb,
        ] + $this->envelope;
    }
}
