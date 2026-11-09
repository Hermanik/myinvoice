<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Databox\Zfo\ZfoDocument;
use MyInvoice\Service\Databox\Zfo\ZfoDocumentParser;
use MyInvoice\Service\Databox\Zfo\ZfoSignatureVerifier;
use MyInvoice\Service\Document\ZfoExtractor;
use Psr\Log\LoggerInterface;

/**
 * "Nahrát ZFO": the owner downloads a signed data message or a signed delivery receipt in the data box portal and uploads it.
 * That is the only way to learn about sent messages and about what happened to a message after its notification e-mail, for a
 * box that is watched by e-mail (no login), and a way to complete the history of any other box.
 *
 * Nothing here talks to ISDS, logs in or delivers anything. A file is accepted only when
 *  1. it is a ZFO (a DER CMS SignedData) whose signature verifies ({@see ZfoSignatureVerifier}; integrity, not the chain),
 *  2. its content is a data message or a delivery receipt ({@see ZfoDocumentParser}),
 *  3. one of the supplier's boxes is the recipient (the message is "received" there) or the sender ("sent" from there).
 *
 * The signed data are authoritative: the envelope, the state and the times replace what the row had (a state never moves
 * backwards, so an older file cannot undo a newer one). A message first created here is acknowledged when it is already
 * delivered, so history does not turn into dashboard items.
 */
final class DataboxZfoImportService
{
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const MAX_FILES = 50;

    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';

    /** Order in which the outcomes of the two directions of one file are folded into one. */
    private const OUTCOME_RANK = [self::DUPLICATE => 0, self::UPDATED => 1, self::CREATED => 2];

    public function __construct(
        private readonly DataboxRepository $boxes,
        private readonly DataboxMessageRepository $messages,
        private readonly ZfoSignatureVerifier $verifier,
        private readonly ZfoDocumentParser $parser,
        private readonly SubmissionFilingBridge $filings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<array{name:string, bytes:?string, problem?:?string}> $files `problem` = the file did not arrive (Czech reason)
     * @return list<array<string,mixed>> one result per file, in the order given:
     *         {name, outcome: created|updated|duplicate|rejected, reason, kind: message|receipt|null, dm_id, direction, databox_id,
     *          message_id, signer, filing: null|{submission_id, status: recorded|kept|not_recorded|linked|no_match, message}}
     * @throws DataboxException when there is nothing to import or too much
     */
    public function import(int $supplierId, int $userId, array $files, ?\DateTimeImmutable $now = null): array
    {
        if ($files === []) {
            throw new DataboxException('validation_failed', 'Nebyl nahrán žádný soubor (možná je celý požadavek větší než povolených 25 MB).', 400);
        }
        if (count($files) > self::MAX_FILES) {
            throw new DataboxException('validation_failed', 'Najednou lze nahrát nejvýše ' . self::MAX_FILES . ' souborů.', 400);
        }
        $now ??= new \DateTimeImmutable();

        $results = [];
        foreach ($files as $file) {
            $name = $this->displayName((string) $file['name']);
            try {
                $results[] = ['name' => $name] + $this->importFile($supplierId, $userId, $file, $now);
            } catch (DataboxException $e) {
                $results[] = ['name' => $name] + $this->rejected($e->getMessage());
            } catch (\Throwable $e) {
                // The file did not do harm (each write is one transaction); the reason stays in the log, not in the answer.
                $this->logger->error('ZFO import failed', ['error' => $e->getMessage(), 'exception' => $e::class]);
                $results[] = ['name' => $name] + $this->rejected('Soubor se nepodařilo zpracovat, zkuste to znovu.');
            }
        }
        return $results;
    }

    // ---- one file ----

    /**
     * @param array{name:string, bytes:?string, problem?:?string} $file
     * @return array<string,mixed>
     * @throws DataboxException
     */
    private function importFile(int $supplierId, int $userId, array $file, \DateTimeImmutable $now): array
    {
        $bytes = $file['bytes'] ?? null;
        if (($file['problem'] ?? null) !== null) {
            throw new DataboxException('upload_failed', (string) $file['problem'], 400);
        }
        if ($bytes === null || $bytes === '') {
            throw new DataboxException('zfo_empty', 'Soubor je prázdný.', 400);
        }
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new DataboxException('zfo_too_large', 'Soubor je větší než 25 MB.', 413);
        }
        if (!ZfoExtractor::looksLikeZfo($bytes)) {
            throw new DataboxException('zfo_not_zfo', 'Soubor není ZFO z datové schránky.', 422);
        }

        ['content' => $content, 'signer' => $signer] = $this->verifier->verify($bytes);
        $doc = $this->parser->parse($content);
        unset($content); // the XML (as big as the file) is no longer needed once it is parsed

        $targets = $this->targets($supplierId, $doc);
        if ($targets === []) {
            throw new DataboxException('zfo_foreign_box', 'Zpráva nepatří k žádné připojené schránce (ani odesílatel, ani příjemce není mezi vašimi schránkami).', 422);
        }

        $imports = [];
        foreach ($targets as [$box, $direction]) {
            $imports[] = $this->importTarget($supplierId, $userId, $box, $direction, $doc, $bytes, $now);
        }
        usort($imports, static fn (array $a, array $b): int => self::OUTCOME_RANK[$b['outcome']] <=> self::OUTCOME_RANK[$a['outcome']]);
        $primary = $imports[0];

        $filing = null;
        foreach ($imports as $i) {
            $filing ??= $i['filing'];
        }
        $reason = implode(' ', array_unique(array_map(static fn (array $i): string => $i['reason'], $imports)));
        return [
            'outcome' => $primary['outcome'],
            'reason' => $reason,
            'kind' => $doc->kind,
            'dm_id' => $doc->dmId,
            'direction' => $primary['direction'],
            'databox_id' => $primary['databox_id'],
            'message_id' => $primary['message_id'],
            'signer' => $signer,
            'filing' => $filing,
        ];
    }

    /**
     * The boxes of the supplier the message belongs to: the recipient's box (received there) and the sender's box (sent from
     * there). Both for a message between two boxes of the same owner.
     *
     * @return list<array{0:array<string,mixed>, 1:'received'|'sent'}>
     */
    private function targets(int $supplierId, ZfoDocument $doc): array
    {
        $targets = [];
        foreach (['received' => $doc->recipientBoxId(), 'sent' => $doc->senderBoxId()] as $direction => $dbId) {
            $box = $dbId !== null ? $this->findBox($supplierId, DataboxId::normalize($dbId)) : null;
            if ($box !== null) {
                $targets[] = [$box, $direction];
            }
        }
        return $targets;
    }

    /** @return array<string,mixed>|null the production box first, then a test one */
    private function findBox(int $supplierId, string $dbId): ?array
    {
        foreach ([IsdsEnvironment::PRODUCTION, IsdsEnvironment::TEST] as $environment) {
            $box = $this->boxes->findByBoxId($supplierId, $environment, $dbId);
            if ($box !== null) {
                return $box;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $box
     * @param 'received'|'sent' $direction
     * @return array{outcome:string, reason:string, direction:string, databox_id:int, message_id:int, filing:?array<string,mixed>}
     */
    private function importTarget(int $supplierId, int $userId, array $box, string $direction, ZfoDocument $doc, string $bytes, \DateTimeImmutable $now): array
    {
        $boxId = (int) $box['id'];
        $existing = $this->messages->findByDmId($boxId, $direction, $doc->dmId);
        $sameFile = $existing !== null && ($this->messages->fileMeta((int) $existing['id'], $doc->fileKind())['sha256'] ?? null) === hash('sha256', $bytes);

        if ($sameFile) {
            $messageId = (int) $existing['id'];
            $outcome = self::DUPLICATE;
        } else {
            $messageId = $this->store($boxId, $direction, $doc, $bytes, $userId, $now);
            $outcome = $existing === null ? self::CREATED : self::UPDATED;
        }

        $filing = null;
        if ($direction === 'sent') {
            // Idempotent, so a repeated upload also completes what an earlier one could not (e.g. the filing archived since).
            $filing = $doc->kind === ZfoDocument::MESSAGE ? $this->recordFiling($supplierId, $userId, $doc, $messageId, $now) : null;
            $this->filings->messageChanged($messageId);
            if ($outcome === self::DUPLICATE && ($filing['status'] ?? null) === 'recorded') {
                $outcome = self::UPDATED;
            }
        }

        $this->logger->info('Data box ZFO imported', ['box_id' => $boxId, 'dm_id' => $doc->dmId, 'kind' => $doc->kind, 'direction' => $direction, 'outcome' => $outcome]);
        return [
            'outcome' => $outcome,
            'reason' => $this->reason($outcome, $doc, $direction, $box, $filing),
            'direction' => $direction,
            'databox_id' => $boxId,
            'message_id' => $messageId,
            'filing' => $filing,
        ];
    }

    /** One transaction: the envelope, the sealed file and (receipts) the readable receipt are stored together or not at all. */
    private function store(int $boxId, string $direction, ZfoDocument $doc, string $bytes, int $userId, \DateTimeImmutable $now): int
    {
        return $this->messages->atomically(function () use ($boxId, $direction, $doc, $bytes, $userId, $now): int {
            $stored = $this->messages->upsertEnvelope($boxId, $direction, $doc->toEnvelope(), $now, 'zfo');
            $this->messages->storeFile($stored['id'], $doc->fileKind(), $bytes);
            if ($doc->deliveryInfoXml !== null) {
                $this->messages->saveDeliveryInfo($stored['id'], $doc->deliveryInfoXml);
                $this->messages->clearReceiptRetry($stored['id']);
            }
            // History is not news: a received message that is already delivered is dealt with. A still undelivered one stays unread.
            if ($stored['is_new'] && $direction === 'received' && DeliveryDates::isDelivered($doc->status)) {
                $this->messages->acknowledge($stored['id'], $userId);
            }
            return $stored['id'];
        });
    }

    // ---- tax filings ----

    /**
     * A sent message that carries the very XML (by SHA-256) of an archived submission is linked to it and, like a message sent
     * from the application, marks the filing as filed (channel data box, reference = the message number, time = the delivery
     * time). Without a checksum match nothing is linked and nothing is filed; the form, period and tax number are never guessed.
     *
     * @return array{submission_id:?int, status:'recorded'|'kept'|'not_recorded'|'linked'|'no_match', message:?string}|null
     */
    private function recordFiling(int $supplierId, int $userId, ZfoDocument $doc, int $messageId, \DateTimeImmutable $now): ?array
    {
        $candidates = $this->messages->submissionsByXmlHash($supplierId, array_values(array_unique(array_column($doc->attachments, 'sha256'))));
        if ($candidates === []) {
            $epo = array_filter($doc->attachments, static fn (array $a): bool => $a['epo']) !== [];
            return $epo ? ['submission_id' => null, 'status' => 'no_match', 'message' => null] : null;
        }
        $submission = self::pickSubmission($candidates, $doc->dmId);
        $this->messages->linkSubmission($submission['id'], $messageId, $userId);
        if (DeliveryDates::isFailed($doc->status)) {
            return ['submission_id' => $submission['id'], 'status' => 'linked', 'message' => null]; // ISDS did not take the message: it filed nothing
        }
        $filedAt = $doc->deliveryTime !== null && $doc->deliveryTime <= $now ? $doc->deliveryTime : $now;
        return ['submission_id' => $submission['id']] + $this->filings->sent($supplierId, $submission['id'], $doc->dmId, $userId, $filedAt);
    }

    /**
     * Several archived rows can hold the same XML: the one this message already filed, else one not filed yet, else the first.
     *
     * @param non-empty-list<array{id:int, filing_status:string, filing_channel:?string, filing_reference:?string}> $candidates
     * @return array{id:int, filing_status:string, filing_channel:?string, filing_reference:?string}
     */
    private static function pickSubmission(array $candidates, string $dmId): array
    {
        foreach ($candidates as $c) {
            if ($c['filing_channel'] === 'data_box' && (string) $c['filing_reference'] === $dmId) {
                return $c;
            }
        }
        foreach ($candidates as $c) {
            if ($c['filing_status'] === 'generated') {
                return $c;
            }
        }
        return $candidates[0];
    }

    // ---- wording ----

    /**
     * @param array<string,mixed> $box
     * @param ?array<string,mixed> $filing
     */
    private function reason(string $outcome, ZfoDocument $doc, string $direction, array $box, ?array $filing): string
    {
        $what = $doc->kind === ZfoDocument::MESSAGE ? 'Zpráva' : 'Doručenka ke zprávě';
        $where = ($direction === 'received' ? 'přijatá' : 'odeslaná') . ' ve schránce „' . ($box['label'] ?: $box['db_id']) . '“';
        $text = match ($outcome) {
            self::CREATED => "{$what} č. {$doc->dmId} uložena ({$where})." . ($doc->kind === ZfoDocument::RECEIPT ? ' Zpráva samotná zatím v aplikaci nebyla, vznikla jen její obálka z doručenky.' : ''),
            self::UPDATED => "{$what} č. {$doc->dmId} už v aplikaci byla ({$where}), doplněna o údaje z podepsaného souboru.",
            default => "Tento soubor už je nahraný ({$what} č. {$doc->dmId}, {$where}), nic se nezměnilo.",
        };
        $ref = ' Přiložené XML odpovídá archivovanému výkazu #' . ($filing['submission_id'] ?? '');
        $filingText = match ($filing['status'] ?? null) {
            'recorded' => "{$ref} - podání je označené jako podané datovou schránkou.",
            'kept' => "{$ref}, ten už byl označený jako podaný dříve, stav zůstal.",
            'not_recorded' => "{$ref}, ale podání se nepodařilo označit jako podané: " . ($filing['message'] ?? ''),
            'linked' => "{$ref}; zpráva nebyla přijata (odmítnuta), takže se nepovažuje za podání.",
            'no_match' => ' Přiložené XML vypadá jako podání EPO, ale neodpovídá žádnému archivovanému výkazu (jiný kontrolní součet) - podání nebylo označeno jako podané.',
            default => '',
        };
        return $text . $filingText;
    }

    /** @return array<string,mixed> */
    private function rejected(string $reason): array
    {
        return ['outcome' => self::REJECTED, 'reason' => $reason, 'kind' => null, 'dm_id' => null, 'direction' => null, 'databox_id' => null, 'message_id' => null, 'signer' => null, 'filing' => null];
    }

    /** The name the user sees: no path, no control characters, bounded. */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 200);
    }
}
