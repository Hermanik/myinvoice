<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;

/**
 * Stores the delivery receipt ("dodejka / doručenka") of a SENT message: the readable XML and the copy sealed by
 * ISDS (ZFO). Both calls need only the "Zobrazovat seznamy, historii a doručenky" right and never deliver anything.
 * The receipt is the proof of delivery: the tax administration states that the delivery status of a data message is
 * evidenced by the receipt kept in the sender's box
 * (https://financnisprava.gov.cz/assets/PAW/Document/BoardFiles/252627_10624954_20250923145746.pdf, item 8c).
 */
final class DataboxReceiptService
{
    public function __construct(
        private readonly IsdsClient $isds,
        private readonly DataboxMessageRepository $messages,
    ) {}

    /**
     * Also updates the lifecycle data of the stored message (state, delivery and acceptance time) from the receipt.
     *
     * @return array{status:?int, acceptance_time:?\DateTimeImmutable}
     * @throws IsdsException
     */
    public function refresh(IsdsAccess $access, int $messageId, string $dmId): array
    {
        $info = $this->isds->deliveryInfo($access, $dmId);
        $this->messages->saveDeliveryInfo($messageId, $info['xml']);
        $this->messages->updateState($messageId, $info['status'], $info['delivery_time'], $info['acceptance_time']);
        // The readable receipt is saved even when the sealed copy fails; the failure then reaches the caller's back-off.
        $this->messages->storeFile($messageId, 'delivery_zfo', $this->isds->signedDeliveryInfo($access, $dmId));
        $this->messages->clearReceiptRetry($messageId);
        return ['status' => $info['status'], 'acceptance_time' => $info['acceptance_time']];
    }
}
