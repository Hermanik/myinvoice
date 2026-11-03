<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use Psr\Log\LoggerInterface;

/**
 * Keeps the filing lifecycle of an archived tax submission (generated -> filed -> confirmed, see
 * {@see TaxSubmissionFilingService}) in step with the data box message that carried it.
 *
 *  - The message is sent -> the submission is marked filed: channel "data_box", reference = the ISDS message ID, time =
 *    the send time for now.
 *  - The delivery receipt arrives -> the time becomes the moment ISDS records the message as delivered (dodána) into the
 *    tax office's box, i.e. when the filing reached the tax administrator (a filing counts from the day it reaches him:
 *    https://financnisprava.gov.cz/assets/cs/prilohy/d-sprava-dani-a-poplatku/91317_22_MP_k_elektronickemu_podani_2023.pdf),
 *    and the sealed receipt (ZFO) becomes the submission's receipt: confirmed.
 *  - The message turns out rejected / undeliverable -> the filing is taken back.
 *
 * Only a filing this very message created (channel data_box, reference = its dmID) is ever touched, so a filing made on
 * the EPO portal, or by an earlier message, is never overwritten by a re-send. Everything goes through the filing
 * service, so its rules (one filed submission per type + period + form, ordering of returns, receipt validation) hold.
 */
final class SubmissionFilingBridge
{
    /** Message states in which ISDS holds a receipt worth keeping: put into the box, delivered by fiction / login, read. */
    private const RECEIPT_STATES = [DeliveryDates::STATE_DELIVERED_TO_BOX, DeliveryDates::STATE_FICTION, DeliveryDates::STATE_DELIVERED, DeliveryDates::STATE_READ];

    public function __construct(
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxSubmissionFilingService $filing,
        private readonly DataboxMessageRepository $messages,
        private readonly LoggerInterface $logger,
    ) {}

    /** True when another archived submission of the same slot is already filed (this one could not be marked filed). @param array<string,mixed> $submission */
    public function hasFiledSibling(array $submission): bool
    {
        return $submission['filing_status'] === 'generated' && $this->submissions->findFiledSibling($submission) !== null;
    }

    /** @param array<string,mixed> $submission @throws DataboxException */
    public function assertNoFiledSibling(array $submission): void
    {
        if ($submission['filing_status'] !== 'generated') {
            return;
        }
        try {
            $this->filing->assertNoFiledSibling($submission);
        } catch (TaxFilingException $e) {
            throw new DataboxException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        }
    }

    /**
     * Everything that can refuse a send of an archived submission, checked right before the payload is consumed: the
     * filing rules (a duplicate filing could not be recorded after the irreversible send) and the "send again"
     * confirmation for a submission that is already filed or was already sent.
     *
     * @throws DataboxException
     */
    public function guardSend(int $supplierId, int $submissionId, bool $resendConfirmed): void
    {
        $submission = $this->submissions->findMeta($submissionId, $supplierId)
            ?? throw new DataboxException('not_found', 'Archivované podání nenalezeno.', 404);
        $this->assertNoFiledSibling($submission);
        if (!$resendConfirmed && $this->requiresResend($submission)) {
            throw new DataboxException('resend_confirmation_required', 'Tento výkaz už byl odeslán nebo je označený jako podaný. Potvrďte, že ho chcete odeslat znovu.', 409);
        }
    }

    /**
     * Whether sending this archived submission is a "send again": it is already filed (EPO or an earlier send) or an
     * earlier data box message already carried it. The one rule behind the prepare summary and the send-time guard.
     *
     * @param array<string,mixed> $submission a row from {@see TaxSubmissionRepository::findMeta()}
     * @param list<array<string,mixed>>|null $activeSends the earlier sends when the caller has them already
     */
    public function requiresResend(array $submission, ?array $activeSends = null): bool
    {
        return $submission['filing_status'] !== 'generated'
            || ($activeSends ?? $this->messages->activeSendsOfSubmission((int) $submission['id'], (int) $submission['supplier_id'])) !== [];
    }

    /**
     * The message was sent: mark the submission filed unless it already is (a re-send leaves the first filing as it is).
     *
     * @return array{status:'recorded'|'kept'|'not_recorded', message:?string}
     */
    public function sent(int $supplierId, int $submissionId, string $dmId, int $userId, \DateTimeImmutable $now): array
    {
        try {
            $this->filing->markFiled($supplierId, $submissionId, $now->format('Y-m-d H:i:s'), 'data_box', $dmId, $userId);
        } catch (TaxFilingException $e) {
            if ($e->errorCode === 'already_filed') {
                return ['status' => 'kept', 'message' => null];
            }
            // The message is out and cannot be recalled: the user has to be told that the filing is not recorded.
            $this->logger->warning('Filing of a sent data box message not recorded', ['submission_id' => $submissionId, 'dm_id' => $dmId, 'reason' => $e->errorCode]);
            return ['status' => 'not_recorded', 'message' => $e->getMessage()];
        }
        return ['status' => 'recorded', 'message' => null];
    }

    /**
     * A SENT message changed in a way that matters to the filing it created: its delivery receipt (or just the readable
     * part) was stored, or the sync saw a new state. Never throws: what changed is already saved, the filing is a
     * consequence that the next refresh or sync retries.
     */
    public function messageChanged(int $messageId): void
    {
        try {
            $submissionIds = $this->messages->submissionsOfMessage($messageId);
            $message = $submissionIds === [] ? null : $this->messages->find($messageId); // a message without a filing costs one lookup
            if ($message === null || $message['direction'] !== 'sent') {
                return;
            }
            foreach ($submissionIds as $submissionId) {
                $submission = $this->submissions->findMeta($submissionId, (int) $message['supplier_id']);
                // A cleared filing has no channel, so the channel alone also says "still filed".
                if ($submission !== null && $submission['filing_channel'] === 'data_box' && (string) $submission['filing_reference'] === (string) $message['dm_id']) {
                    $this->reconcile($submission, $message);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Filing could not follow the delivery receipt', ['message_id' => $messageId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $message
     */
    private function reconcile(array $submission, array $message): void
    {
        $supplierId = (int) $submission['supplier_id'];
        $id = (int) $submission['id'];

        if (DeliveryDates::isFailed($message['status'])) {
            if ($submission['filing_status'] === 'filed') {
                $this->guarded(fn () => $this->filing->revertFiling($supplierId, $id, null), $id);
            }
            return;
        }
        if ($message['delivery_time'] !== null && $message['delivery_time'] !== $submission['filed_at']) {
            $this->guarded(fn () => $this->filing->adjustFiledAt($supplierId, $id, (string) $message['delivery_time'], null), $id);
        }
        if ($submission['filing_status'] === 'filed' && in_array($message['status'], self::RECEIPT_STATES, true)) {
            $zfo = $this->messages->fileBytes((int) $message['id'], 'delivery_zfo');
            if ($zfo !== null) {
                $this->guarded(fn () => $this->filing->attachReceipt($supplierId, $id, $zfo, DataboxMessageService::zfoFileName((string) $message['dm_id'], 'delivery_zfo'), null), $id);
            }
        }
    }

    /** A rule of the filing service refusing one step must not stop the next one; it is logged for the record. */
    private function guarded(callable $step, int $submissionId): void
    {
        try {
            $step();
        } catch (TaxFilingException $e) {
            $this->logger->warning('Filing step after the delivery receipt refused', ['submission_id' => $submissionId, 'reason' => $e->errorCode]);
        }
    }
}
