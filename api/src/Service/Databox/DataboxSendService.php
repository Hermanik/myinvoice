<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxOutboxRepository;
use MyInvoice\Repository\DataboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Two-step sending: {@see prepare()} validates and STAGES the exact payload and returns a one-time token plus a
 * summary; only {@see send()} with that token (the user's explicit confirmation, every single time) calls ISDS.
 * Nothing in the application sends automatically, and an API / MCP bearer token cannot reach these routes.
 *
 * The staged payload is consumed BEFORE the ISDS call. After an ambiguous failure (timeout) the message may
 * have been created, so a retry must start with a fresh "prepare" - and the user is told to check the sent list first.
 */
final class DataboxSendService
{
    /** A regular data message may carry at most 20 MB of attachments (WS spec 2.1.2); larger "VoDZ" are not supported here. */
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
    public const MAX_FILES = 100;
    public const TOKEN_TTL = '+30 minutes';

    /** Extensions that ISDS accepts for the common cases, with the MIME type it expects (WS spec chapter 3). */
    private const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf', 'xml' => 'application/xml', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'json' => 'application/json', 'zip' => 'application/zip', 'zfo' => 'application/vnd.software602.filler.form-xml-zip',
        'doc' => 'application/msword', 'rtf' => 'application/rtf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'isdoc' => 'application/x-isdoc', 'p7s' => 'application/pkcs7-signature',
    ];

    public function __construct(
        private readonly DataboxRepository $boxes,
        private readonly DataboxMessageRepository $messages,
        private readonly DataboxOutboxRepository $outbox,
        private readonly DataboxService $service,
        private readonly DataboxReceiptService $receipts,
        private readonly IsdsClient $isds,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validate and stage.
     *
     * @param array{recipient_box_id:string, annotation:string, to_hands?:?string, sender_ref_number?:?string,
     *              recipient_ref_number?:?string, personal_delivery?:bool} $input
     * @param list<array{name:string, bytes:string}> $files
     * @param array{recipient_name?:?string, is_tax_office?:bool, warnings?:list<string>, previous_sends?:list<array<string,mixed>>, form?:?array<string,mixed>} $context
     * @return array{token:string, expires_at:string, summary:array<string,mixed>}
     * @throws DataboxException
     */
    public function prepare(int $supplierId, int $userId, int $boxId, array $input, array $files, ?int $submissionId = null, array $context = [], ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->outbox->purgeExpired($now);
        $box = $this->service->requireBox($supplierId, $boxId);
        if (!$box['is_active']) {
            throw new DataboxException('box_inactive', 'Datová schránka je vypnutá.', 409);
        }

        $recipient = DataboxId::normalize((string) ($input['recipient_box_id'] ?? ''));
        if (!DataboxId::isValid($recipient)) {
            throw new DataboxException('validation_failed', 'ID datové schránky příjemce není platné (7 znaků s kontrolním znakem).', 400);
        }
        if ($recipient === $box['db_id']) {
            throw new DataboxException('validation_failed', 'Zprávu nelze poslat do vlastní datové schránky.', 400);
        }
        $annotation = $this->singleLine((string) ($input['annotation'] ?? ''));
        if ($annotation === '') {
            throw new DataboxException('validation_failed', 'Vyplňte věc zprávy (anotaci).', 400);
        }
        if (mb_strlen($annotation) > 255) {
            throw new DataboxException('validation_failed', 'Věc zprávy může mít nejvýše 255 znaků.', 400);
        }

        $prepared = $this->prepareFiles($files);
        $this->assertCanSend($box);

        $envelope = [
            'recipient_box_id' => $recipient,
            'annotation' => $annotation,
            'to_hands' => $this->optionalText($input['to_hands'] ?? null, 30),
            'sender_ref_number' => $this->optionalText($input['sender_ref_number'] ?? null, 50),
            'recipient_ref_number' => $this->optionalText($input['recipient_ref_number'] ?? null, 50),
            'personal_delivery' => (bool) ($input['personal_delivery'] ?? false),
        ];
        $recipientName = $context['recipient_name'] ?? $this->lookupName($box, $recipient);
        $warnings = $context['warnings'] ?? [];
        $previous = $context['previous_sends'] ?? [];
        $summary = [
            'box' => ['id' => $box['id'], 'label' => $box['label'], 'db_id' => $box['db_id'], 'db_type' => $box['db_type'], 'owner_name' => $box['owner_name']],
            'recipient' => ['box_id' => $recipient, 'name' => $recipientName, 'verified' => $recipientName !== null, 'is_tax_office' => (bool) ($context['is_tax_office'] ?? TaxOfficeCodebook::isTaxOfficeBox($recipient))],
            'annotation' => $annotation,
            'to_hands' => $envelope['to_hands'],
            'personal_delivery' => $envelope['personal_delivery'],
            'files' => array_map(static fn (array $f): array => ['name' => $f['name'], 'mime' => $f['mime'], 'size' => strlen($f['bytes']), 'sha256' => hash('sha256', $f['bytes'])], $prepared),
            'total_bytes' => array_sum(array_map(static fn (array $f): int => strlen($f['bytes']), $prepared)),
            'warnings' => $warnings,
            'previous_sends' => $previous,
            'requires_resend_confirmation' => $previous !== [],
            'form' => $context['form'] ?? null,
        ];

        $token = bin2hex(random_bytes(16));
        $expires = $now->modify(self::TOKEN_TTL);
        $this->outbox->stage($token, $supplierId, $boxId, $userId, $submissionId, $envelope, $summary, $prepared, $now, $expires);
        return ['token' => $token, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'summary' => $summary];
    }

    /**
     * The explicit confirmation: send the staged payload exactly once.
     *
     * @return array{message_id:int, dm_id:string, submission_id:?int, receipt:bool, zfo:bool}
     * @throws DataboxException|IsdsException
     */
    public function send(int $supplierId, int $userId, string $token, bool $confirmed, bool $resendConfirmed = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        if (!$confirmed) {
            throw new DataboxException('confirmation_required', 'Odeslání je nutné výslovně potvrdit.', 400);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new DataboxException('token_invalid', 'Potvrzení odeslání je neplatné nebo vypršelo. Připravte zprávu znovu.', 410);
        }

        // Everything that can fail without side effects happens before the payload is consumed.
        $header = $this->outbox->peek($token, $now);
        if ($header === null || $header['supplier_id'] !== $supplierId || $header['user_id'] !== $userId) {
            throw new DataboxException('token_invalid', 'Potvrzení odeslání je neplatné nebo vypršelo. Připravte zprávu znovu.', 410);
        }
        // Re-checked here, not only at prepare time: the filing may have been sent from another tab meanwhile.
        if ($header['submission_id'] !== null && !$resendConfirmed && $this->messages->activeSendsOfSubmission($header['submission_id'], $supplierId) !== []) {
            throw new DataboxException('resend_confirmation_required', 'Tento výkaz už byl datovou schránkou odeslán. Potvrďte, že ho chcete odeslat znovu.', 409);
        }
        $box = $this->service->requireBox($supplierId, $header['databox_id']);
        $access = $this->service->accessForRight($box, static fn (DataboxPrivileges $p): bool => $p->canSend(), 'Posílat zprávy a číst odeslané');
        $staged = $this->outbox->consume($token, $now)
            ?? throw new DataboxException('token_invalid', 'Zprávu mezitím odeslal jiný požadavek. Zkontrolujte záložku Odeslané.', 410);

        $files = [];
        foreach ($staged['files'] as $i => $file) {
            $files[] = ['name' => $file['name'], 'mime' => $file['mime'], 'meta_type' => $i === 0 ? 'main' : 'enclosure', 'bytes' => $file['bytes']];
        }
        try {
            $dmId = $this->isds->createMessage($access, $staged['envelope'], $files);
        } catch (IsdsException $e) {
            if ($e->isTransient() || $e->reason === 'invalid_response') {
                throw new IsdsException(
                    $e->reason,
                    $e->getMessage() . ' Zpráva mohla být přesto odeslána - před dalším pokusem zkontrolujte záložku Odeslané.',
                    $e->isdsCode,
                    $e->httpStatus,
                    null,
                    $e,
                );
            }
            throw $e;
        }
        $this->logger->info('Data box message sent', ['box_id' => $box['id'], 'dm_id' => $dmId, 'files' => count($files), 'user_id' => $userId]);

        $recipient = $staged['summary']['recipient'] ?? [];
        $stored = $this->messages->upsertEnvelope((int) $box['id'], 'sent', [
            'dm_id' => $dmId,
            'sender_box_id' => $box['db_id'],
            'sender_name' => $box['owner_name'],
            'recipient_box_id' => $staged['envelope']['recipient_box_id'],
            'recipient_name' => $recipient['name'] ?? null,
            'annotation' => $staged['envelope']['annotation'],
            'to_hands' => $staged['envelope']['to_hands'] ?? null,
            'sender_ref_number' => $staged['envelope']['sender_ref_number'] ?? null,
            'recipient_ref_number' => $staged['envelope']['recipient_ref_number'] ?? null,
            'personal_delivery' => $staged['envelope']['personal_delivery'] ?? false,
            'attachment_size_kb' => (int) ceil(($staged['summary']['total_bytes'] ?? 0) / 1024),
            'status' => null,
        ], $now);
        $messageId = $stored['id'];
        $this->messages->saveSentMeta($messageId, $userId, [
            'files' => $staged['summary']['files'] ?? [],
            'form' => $staged['summary']['form'] ?? null,
            'submission_id' => $staged['submission_id'],
        ]);
        $this->messages->acknowledge($messageId, $userId);
        if ($staged['submission_id'] !== null) {
            $this->messages->linkSubmission($staged['submission_id'], $messageId, $userId);
        }

        // Best effort from here on: the message is sent and recorded, the receipt and the sealed copy can be fetched later.
        $receipt = false;
        try {
            $this->receipts->refresh($access, $messageId, $dmId);
            $receipt = true;
        } catch (IsdsException $e) {
            $this->logger->warning('Receipt after send failed', ['dm_id' => $dmId, 'reason' => $e->reason]);
        }
        $zfo = false;
        try {
            $this->messages->storeFile($messageId, 'zfo', $this->isds->downloadSentAfterSend($access, $dmId));
            $zfo = true;
        } catch (IsdsException $e) {
            $this->logger->info('Sealed copy not stored right after send', ['dm_id' => $dmId, 'reason' => $e->reason]);
        }

        return ['message_id' => $messageId, 'dm_id' => $dmId, 'submission_id' => $staged['submission_id'], 'receipt' => $receipt, 'zfo' => $zfo];
    }

    // ---- internals ----

    /**
     * @param list<array{name:string, bytes:string}> $files
     * @return list<array{name:string, mime:string, bytes:string}>
     */
    private function prepareFiles(array $files): array
    {
        if ($files === []) {
            throw new DataboxException('validation_failed', 'Zpráva musí obsahovat alespoň jednu přílohu.', 400);
        }
        if (count($files) > self::MAX_FILES) {
            throw new DataboxException('validation_failed', 'Zpráva může mít nejvýše ' . self::MAX_FILES . ' příloh.', 400);
        }
        $out = [];
        $total = 0;
        $names = [];
        foreach ($files as $file) {
            $name = $this->fileName((string) $file['name']);
            $bytes = (string) $file['bytes'];
            if ($bytes === '') {
                throw new DataboxException('validation_failed', "Příloha „{$name}“ je prázdná.", 400);
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = self::MIME_BY_EXTENSION[$ext] ?? null;
            if ($mime === null) {
                throw new DataboxException('validation_failed', "Přípona souboru „{$name}“ není povolená pro datové zprávy (povolené: " . implode(', ', array_keys(self::MIME_BY_EXTENSION)) . ').', 400);
            }
            if ($ext === 'xml' && !$this->isWellFormedXml($bytes)) {
                throw new DataboxException('validation_failed', "Soubor „{$name}“ není platné XML.", 400);
            }
            if (isset($names[strtolower($name)])) {
                throw new DataboxException('validation_failed', "Název přílohy „{$name}“ je použit dvakrát.", 400);
            }
            $names[strtolower($name)] = true;
            $total += strlen($bytes);
            $out[] = ['name' => $name, 'mime' => $mime, 'bytes' => $bytes];
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new DataboxException('validation_failed', 'Přílohy dohromady přesahují 20 MB. Větší zprávy aplikace neodesílá.', 413);
        }
        return $out;
    }

    /** @param array<string,mixed> $box */
    private function assertCanSend(array $box): void
    {
        foreach (['action', 'sync'] as $purpose) {
            $cred = $box['credentials'][$purpose] ?? null;
            $p = $cred !== null ? $this->service->privilegesOf($cred) : null;
            if ($cred !== null && ($p === null || $p->canSend())) {
                return; // unknown privileges are resolved at send time
            }
        }
        throw new DataboxException(
            'missing_right',
            'Žádné z uložených přihlášení k této schránce nemá právo „Posílat zprávy a číst odeslané“. Přidejte v nastavení přihlášení pro akce s tímto právem.',
            422,
            ['missing_right' => 'Posílat zprávy a číst odeslané'],
        );
    }

    /** Verify a recipient with the search service when a login may search; null when it cannot be confirmed. @param array<string,mixed> $box */
    private function lookupName(array $box, string $recipient): ?string
    {
        $known = TaxOfficeCodebook::nameForBox($recipient);
        if ($known !== null) {
            return $known;
        }
        try {
            $access = $this->service->accessForRight($box, static fn (DataboxPrivileges $p): bool => $p->canSearch(), 'Vyhledávat schránky');
            $found = $this->isds->findDataBox($access, ['db_id' => $recipient]);
            return $found['boxes'][0]['name'] ?? null;
        } catch (DataboxException | IsdsException) {
            return null;
        }
    }

    private function singleLine(string $text): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        $text = $this->singleLine((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new DataboxException('validation_failed', "Údaj „{$text}“ může mít nejvýše {$max} znaků.", 400);
        }
        return $text;
    }

    private function fileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name); // no bidi spoofing
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|*?:\/\\\\]/u', '_', $name));
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 255) {
            throw new DataboxException('validation_failed', 'Neplatný název přílohy.', 400);
        }
        return $name;
    }

    private function isWellFormedXml(string $xml): bool
    {
        try {
            IsdsResponseParser::load($xml);
            return true;
        } catch (IsdsException) {
            return false;
        }
    }
}
