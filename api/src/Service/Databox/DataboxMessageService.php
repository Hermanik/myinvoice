<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\ZfoExtractor;
use Psr\Log\LoggerInterface;

/**
 * Reading stored messages and the explicit "open" action that downloads the signed message.
 *
 * Legal model of opening a RECEIVED message (WS spec 1.8.1 and the operating rules, chapter 4):
 *  - A message cannot be downloaded until it is delivered: ISDS refuses states 4 (put into the box) and 5 (delivery
 *    by fiction) with error 1222.
 *  - The only way to deliver is a "login", which for an external application means listing the received messages
 *    with a user who may read them. That call delivers state 4 -> 6 (legal deadlines start) and turns state 5 -> 6
 *    without moving the already fixed fiction date.
 *  - So opening an undelivered message IS the act of delivering it, and it can deliver other undelivered messages
 *    in the same call. {@see open()} therefore refuses to start without an explicit acknowledgement.
 *  - An already delivered message (state 6/7) is downloaded without any list call and without side effects.
 */
final class DataboxMessageService
{
    /** Half-width of the listing window around the delivery time (seconds): the narrowest one that still matches. */
    private const DELIVERY_WINDOW_SECONDS = 2;

    public function __construct(
        private readonly DataboxRepository $boxes,
        private readonly DataboxMessageRepository $messages,
        private readonly DataboxService $service,
        private readonly DataboxReceiptService $receipts,
        private readonly IsdsClient $isds,
        private readonly ZfoExtractor $zfo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array{direction?:?string, unread_only?:bool, tax_office_only?:bool, search?:?string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function list(int $supplierId, int $boxId, array $filters, int $page, int $perPage): array
    {
        $this->service->requireBox($supplierId, $boxId);
        $perPage = max(1, min(200, $perPage));
        $result = $this->messages->list($boxId, $filters, $perPage, (max(1, $page) - 1) * $perPage);
        $result['items'] = array_map(fn (array $m): array => $this->decorate($m), $result['items']);
        return $result;
    }

    /** @return array<string,mixed> the message with state explanations, deadline hints, receipt and (if stored) attachments */
    public function detail(int $supplierId, int $messageId): array
    {
        $message = $this->requireMessage($supplierId, $messageId);
        $out = $this->decorate($message);

        $out['attachments'] = null;
        if ($message['has_zfo']) {
            $out['attachments'] = $this->attachmentList($messageId);
        }
        $out['receipt'] = $message['delivery_info_xml'] !== null ? $this->receiptView((string) $message['delivery_info_xml']) : null;
        unset($out['delivery_info_xml']);

        $out['open'] = $this->openPlan($message, $this->requireStoredBox((int) $message['databox_id']));
        $out['file'] = $this->messages->fileMeta($messageId, 'zfo');
        return $out;
    }

    /**
     * Download and store the signed message (explicit user action).
     *
     * @param bool $acknowledgeDelivery the user confirmed that opening may deliver the message
     * @return array<string,mixed> {@see detail()}
     * @throws DataboxException|IsdsException
     */
    public function open(int $supplierId, int $messageId, int $userId, bool $acknowledgeDelivery): array
    {
        $message = $this->requireMessage($supplierId, $messageId);
        $box = $this->requireStoredBox((int) $message['databox_id']);
        $this->service->requireIsdsMode($box);

        if ($message['has_zfo']) {
            $this->messages->acknowledge($messageId, $userId);
            return $this->detail($supplierId, $messageId);
        }

        if ($message['direction'] === 'sent') {
            $access = $this->service->accessForRight($box, static fn (DataboxPrivileges $p): bool => $p->canSend(), 'Posílat zprávy a číst odeslané');
            $bytes = $this->isds->downloadSentAfterSend($access, (string) $message['dm_id'], 3, 2);
            $this->messages->storeFile($messageId, 'zfo', $bytes);
            return $this->detail($supplierId, $messageId);
        }

        $plan = $this->openPlan($message, $box);
        if ($plan['blocked_reason'] === 'delivery_time_unknown') {
            throw new DataboxException(
                'delivery_time_unknown',
                'U zprávy chybí čas dodání, takže ji nelze otevřít bez doručení dalších zpráv. Zprávu otevřete v datovce nebo na portálu datových schránek.',
                409,
            );
        }
        if ($plan['needs_acknowledgement'] && !$acknowledgeDelivery) {
            throw new DataboxException(
                'delivery_ack_required',
                'Otevření této zprávy ji může doručit ve smyslu zákona (začnou běžet lhůty). Potvrďte, že chcete zprávu otevřít.',
                409,
                ['undelivered' => $plan['undelivered'], 'fiction_date' => $plan['fiction_date']],
            );
        }

        $personal = (bool) $message['personal_delivery'];
        $access = $this->service->accessForRight(
            $box,
            static fn (DataboxPrivileges $p): bool => $personal ? $p->canReadPersonal() : $p->canReadReceived(),
            $personal ? 'Číst přijaté zprávy do vlastních rukou' : 'Číst přijaté zprávy',
        );

        if ($plan['needs_acknowledgement']) {
            $this->deliverByLogin($access, $box, $message);
        }

        try {
            $bytes = $this->isds->downloadReceived($access, (string) $message['dm_id']);
        } catch (IsdsException $e) {
            if ($e->reason === 'not_delivered') {
                throw new DataboxException('delivery_not_confirmed', 'Systém datových schránek zprávu dosud nepovažuje za doručenou, zkuste to za chvíli.', 409);
            }
            throw $e;
        }
        $this->messages->storeFile($messageId, 'zfo', $bytes);
        $this->messages->acknowledge($messageId, $userId);
        $this->logger->info('Data box message opened', ['message_id' => $messageId, 'box_id' => $box['id'], 'was_undelivered' => $plan['undelivered']]);
        return $this->detail($supplierId, $messageId);
    }

    /**
     * One attachment of the stored message.
     *
     * @return array{name:string, mime:string, bytes:string}
     */
    public function attachment(int $supplierId, int $messageId, int $index): array
    {
        $this->requireMessage($supplierId, $messageId);
        $bytes = $this->messages->fileBytes($messageId, 'zfo') ?? throw new DataboxException('not_opened', 'Zpráva ještě nebyla otevřena a stažena.', 404);
        $extracted = $this->extract($bytes);
        $file = $extracted['attachments'][$index] ?? throw new DataboxException('not_found', 'Příloha nenalezena.', 404);
        return ['name' => $file['name'], 'mime' => $file['mime'], 'bytes' => $file['bytes']];
    }

    /**
     * The stored signed file.
     *
     * @param 'zfo'|'delivery_zfo' $kind
     * @return array{filename:string, bytes:string}
     */
    public function file(int $supplierId, int $messageId, string $kind): array
    {
        $message = $this->requireMessage($supplierId, $messageId);
        $bytes = $this->messages->fileBytes($messageId, $kind) ?? throw new DataboxException('not_found', 'Soubor není uložen.', 404);
        return ['filename' => self::zfoFileName((string) $message['dm_id'], $kind), 'bytes' => $bytes];
    }

    /** Download name of a stored sealed file; also the name of a delivery receipt kept with a tax filing. */
    public static function zfoFileName(string $dmId, string $kind): string
    {
        return 'datova-zprava-' . $dmId . ($kind === 'zfo' ? '' : '-dorucenka') . '.zfo';
    }

    /**
     * Re-read the delivery receipt of a SENT message (needs only the list right, never delivers).
     *
     * @return array<string,mixed> {@see detail()}
     */
    public function refreshReceipt(int $supplierId, int $messageId): array
    {
        $message = $this->requireMessage($supplierId, $messageId);
        if ($message['direction'] !== 'sent') {
            throw new DataboxException('validation_failed', 'Doručenku lze načíst jen u odeslané zprávy.', 400);
        }
        $box = $this->requireStoredBox((int) $message['databox_id']);
        $access = $this->service->accessForRight($box, static fn (DataboxPrivileges $p): bool => $p->canList(), 'Zobrazovat seznamy, historii a doručenky');
        $this->receipts->refresh($access, $messageId, (string) $message['dm_id']);
        return $this->detail($supplierId, $messageId);
    }

    public function acknowledge(int $supplierId, int $messageId, int $userId): void
    {
        $this->requireMessage($supplierId, $messageId);
        $this->messages->acknowledge($messageId, $userId);
    }

    public function acknowledgeAll(int $supplierId, int $boxId, int $userId): int
    {
        $this->service->requireBox($supplierId, $boxId);
        return $this->messages->acknowledgeAll($boxId, $userId);
    }

    // ---- internals ----

    /**
     * The "login": list the undelivered envelopes around the message with a reading user. This is the step that
     * legally delivers; afterwards the message is state 6 and downloadable. Every returned envelope is refreshed locally.
     *
     * @param array<string,mixed> $box
     * @param array<string,mixed> $message
     */
    private function deliverByLogin(IsdsAccess $access, array $box, array $message): void
    {
        // The caller guarantees the delivery time; the window is as narrow as the second-level timestamps allow.
        $at = new \DateTimeImmutable((string) $message['delivery_time']);
        $from = $at->modify('-' . self::DELIVERY_WINDOW_SECONDS . ' seconds');
        $to = $at->modify('+' . self::DELIVERY_WINDOW_SECONDS . ' seconds');
        $now = new \DateTimeImmutable();

        $delivered = false;
        foreach ($this->isds->listReceived($access, $from, $to, -1, 1, 1000) as $envelope) {
            if (empty($envelope['dm_id'])) {
                continue;
            }
            $this->messages->upsertEnvelope((int) $box['id'], 'received', $envelope, $now);
            if ((string) $envelope['dm_id'] === (string) $message['dm_id'] && in_array($envelope['status'], [6, 7], true)) {
                $delivered = true;
            }
        }
        if (!$delivered) {
            throw new DataboxException('delivery_not_confirmed', 'Zprávu se nepodařilo doručit přihlášením (v seznamu schránky se neobjevila jako doručená). Zkuste to později.', 409);
        }
    }

    /**
     * What opening this message would do - shown before the user clicks.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $box
     * @return array<string,mixed>
     */
    private function openPlan(array $message, array $box): array
    {
        $received = $message['direction'] === 'received';
        $status = $message['status'];
        $undelivered = $received && DeliveryDates::isUndelivered($status);

        $capable = false;
        foreach (['action', 'sync'] as $purpose) {
            $cred = $box['credentials'][$purpose] ?? null;
            $privileges = $cred !== null ? $this->service->privilegesOf($cred) : null;
            if ($privileges !== null) {
                $capable = $capable || ($received
                    ? ($message['personal_delivery'] ? $privileges->canReadPersonal() : $privileges->canReadReceived())
                    : $privileges->canSend());
            }
        }

        $needsLogin = $received && !$message['has_zfo'] && DeliveryDates::needsLoginBeforeDownload($status);

        return [
            'stored' => (bool) $message['has_zfo'],
            // A box watched by e-mail has no login: the message can only be opened in the data box portal.
            'email_only' => DataboxService::isEmailOnly($box),
            'needs_acknowledgement' => $needsLogin,
            // Without the delivery time the login cannot be narrowed to this one message, and a wider listing would
            // deliver (start the legal deadlines of) other undelivered messages as well: such a message is not opened here.
            'blocked_reason' => $needsLogin && $message['delivery_time'] === null ? 'delivery_time_unknown' : null,
            'undelivered' => $undelivered,
            'fiction_date' => $undelivered && ($message['allow_subst_delivery'] ?? true) !== false && $message['delivery_time'] !== null
                ? DeliveryDates::fictionDate(new \DateTimeImmutable((string) $message['delivery_time']))->format('Y-m-d')
                : null,
            'has_capable_login' => $capable,
        ];
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private function decorate(array $message): array
    {
        $message['tax_office_hints'] = $message['is_tax_office'] ? TaxOfficeMessageClassifier::deadlineHints($message) : [];
        $message['state'] = DeliveryDates::stateName($message['status']);
        return $message;
    }

    /** @return list<array{index:int, name:string, mime:string, meta_type:string, size:int}> */
    private function attachmentList(int $messageId): array
    {
        $bytes = $this->messages->fileBytes($messageId, 'zfo');
        if ($bytes === null) {
            return [];
        }
        try {
            $extracted = $this->extract($bytes);
        } catch (DataboxException) {
            return [];
        }
        $out = [];
        foreach ($extracted['attachments'] as $i => $file) {
            $out[] = ['index' => $i, 'name' => $file['name'], 'mime' => $file['mime'], 'meta_type' => $file['meta_type'], 'size' => strlen($file['bytes'])];
        }
        return $out;
    }

    /** @return array{metadata:array<string,mixed>, attachments:list<array{name:string,mime:string,meta_type:string,bytes:string}>, envelope_xml:string} */
    private function extract(string $zfo): array
    {
        try {
            return $this->zfo->extract($zfo);
        } catch (DocumentException $e) {
            throw new DataboxException('zfo_unreadable', 'Uloženou zprávu (ZFO) se nepodařilo přečíst: ' . $e->getMessage(), 422);
        }
    }

    /** @return array{events:list<array{time:?string, text:string}>} */
    private function receiptView(string $xml): array
    {
        $events = [];
        try {
            $doc = IsdsResponseParser::load($xml);
            $xp = new \DOMXPath($doc);
            foreach ($xp->query("//*[local-name()='dmEvent']") as $event) {
                $events[] = [
                    'time' => IsdsResponseParser::dateTime(IsdsResponseParser::text($event, 'dmEventTime'))?->format('Y-m-d H:i:s'),
                    'text' => IsdsResponseParser::text($event, 'dmEventDescr') ?? '',
                ];
            }
        } catch (IsdsException) {
            // the stored copy is shown raw by the API consumer if it ever fails to parse
        }
        return ['events' => $events];
    }

    /** @return array<string,mixed> @throws DataboxException */
    private function requireStoredBox(int $boxId): array
    {
        return $this->boxes->find($boxId) ?? throw new DataboxException('not_found', 'Datová schránka nenalezena.', 404);
    }

    /** @return array<string,mixed> @throws DataboxException */
    private function requireMessage(int $supplierId, int $messageId): array
    {
        $message = $this->messages->find($messageId);
        if ($message === null || (int) $message['supplier_id'] !== $supplierId) {
            throw new DataboxException('not_found', 'Zpráva nenalezena.', 404);
        }
        return $message;
    }
}
