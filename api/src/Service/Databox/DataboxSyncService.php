<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Background synchronisation of the message ENVELOPES of a box (received + sent lists). No content is downloaded.
 *
 * Legal safety: listing received messages is the one ISDS call that delivers messages for a user who may read them
 * (see {@see DataboxPrivileges}). The sync therefore runs only with a login that cannot read ("safe"), or with a
 * reading login the owner explicitly approved for this box ("opt_in"). Every other state is refused before any list
 * call is made.
 *
 * A failed login pauses the box: repeated wrong passwords lock the ISDS account, so the cron must not retry
 * with credentials that were just refused.
 */
final class DataboxSyncService
{
    /** The first run reads this far back: ISDS keeps a delivered message 90 days (WS spec 1.7), undelivered ones up to 3 years. */
    public const FIRST_SYNC_DAYS = 100;
    /** Later runs re-read this window, which is enough to see state changes (delivery, receipts) of recent messages. */
    public const WINDOW_DAYS = 35;
    private const PAGE = 1000;
    private const CHUNK_DAYS = 40;
    private const RECEIPTS_PER_RUN = 20;
    /** After a refused login the box stays untouched for this long (or until the owner re-tests the login). */
    private const PAUSE_AFTER_AUTH_FAILURE = '+6 hours';

    public function __construct(
        private readonly DataboxRepository $boxes,
        private readonly DataboxMessageRepository $messages,
        private readonly DataboxService $service,
        private readonly DataboxReceiptService $receipts,
        private readonly IsdsClient $isds,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array<string,mixed>> one report per active box
     */
    public function syncAll(?\DateTimeImmutable $now = null): array
    {
        $reports = [];
        foreach ($this->boxes->allActive() as $box) {
            $reports[] = $this->syncBox($box, $now);
        }
        return $reports;
    }

    /**
     * @param array<string,mixed> $box
     * @return array{box_id:int, label:string, status:string, message:?string, received_new:int, received_new_tax_office:int, sent_new:int, updated:int, receipts:int}
     */
    public function syncBox(array $box, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $boxId = (int) $box['id'];
        $report = [
            'box_id' => $boxId, 'label' => (string) $box['label'], 'status' => 'skipped', 'message' => null,
            'received_new' => 0, 'received_new_tax_office' => 0, 'sent_new' => 0, 'updated' => 0, 'receipts' => 0,
        ];

        if ($box['sync_paused_until'] !== null && new \DateTimeImmutable((string) $box['sync_paused_until']) > $now) {
            $report['status'] = 'paused';
            $report['message'] = 'Synchronizace je pozastavená po neúspěšném přihlášení. Ověřte připojení v nastavení.';
            return $report;
        }

        try {
            $box = $this->ensureVerified($box);
            $refusal = $this->refusal($box);
            if ($refusal !== null) {
                $this->markFailed($boxId, 'error', $refusal);
                $report['status'] = 'refused';
                $report['message'] = $refusal;
                return $report;
            }

            $access = $this->service->accessFor($box, 'sync');
            $from = $now->modify('-' . ($box['last_sync_at'] === null ? self::FIRST_SYNC_DAYS : self::WINDOW_DAYS) . ' days')->setTime(0, 0);
            // End in the future: the specification warns about clocks that are ahead and late-indexed messages.
            $to = $now->modify('+1 day');

            foreach (['received', 'sent'] as $direction) {
                foreach ($this->window($access, $direction, $from, $to) as $envelope) {
                    $result = $this->messages->upsertEnvelope($boxId, $direction, $envelope, $now);
                    if (!$result['is_new']) {
                        $report['updated']++;
                    } elseif ($direction === 'sent') {
                        $report['sent_new']++;
                    } else {
                        $report['received_new']++;
                        $report['received_new_tax_office'] += (int) $result['is_tax_office'];
                    }
                }
            }

            foreach ($this->messages->sentNeedingReceipt($boxId, self::RECEIPTS_PER_RUN) as $sent) {
                try {
                    $this->receipts->refresh($access, $sent['id'], $sent['dm_id']);
                    $report['receipts']++;
                } catch (IsdsException $e) {
                    if ($e->isAuthFailure()) {
                        throw $e;
                    }
                    $this->logger->warning('Data box receipt refresh failed', ['box_id' => $boxId, 'dm_id' => $sent['dm_id'], 'reason' => $e->reason]);
                }
            }

            $report['status'] = 'ok';
            $this->boxes->update($boxId, [
                'last_sync_at' => $now->format('Y-m-d H:i:s'),
                'last_sync_status' => 'ok',
                'last_sync_message' => null,
                'sync_paused_until' => null,
            ]);
        } catch (IsdsException $e) {
            $authFailure = $e->isAuthFailure();
            $report['status'] = $authFailure ? 'paused' : 'error';
            $report['message'] = $e->getMessage();
            $pausedUntil = null;
            if ($authFailure) {
                $pausedUntil = $e->blockedUntil($now) ?? $now->modify(self::PAUSE_AFTER_AUTH_FAILURE);
                $this->boxes->recordCheck($boxId, 'sync', $e->reason === 'bad_credentials' ? 'bad_credentials' : 'locked', $e->getMessage());
            }
            $this->markFailed($boxId, $authFailure ? 'paused' : 'error', $e->getMessage(), $pausedUntil);
            $this->logger->warning('Data box sync failed', ['box_id' => $boxId, 'reason' => $e->reason, 'isds_code' => $e->isdsCode]);
        } catch (DataboxException $e) {
            $report['status'] = 'error';
            $report['message'] = $e->getMessage();
            $this->markFailed($boxId, 'error', $e->getMessage());
        }
        return $report;
    }

    private function markFailed(int $boxId, string $status, string $message, ?\DateTimeImmutable $pausedUntil = null): void
    {
        $update = ['last_sync_status' => $status, 'last_sync_message' => mb_substr($message, 0, 500)];
        if ($pausedUntil !== null) {
            $update['sync_paused_until'] = $pausedUntil->format('Y-m-d H:i:s');
        }
        $this->boxes->update($boxId, $update);
    }

    /**
     * Why the sync must NOT run for this box, or null when it may. The decisive rule of the feature.
     *
     * @param array<string,mixed> $box
     */
    public function refusal(array $box): ?string
    {
        return match ($this->service->syncState($box)['state']) {
            'ok', 'opt_in' => null,
            'blocked_delivers' => 'Přihlášení pro synchronizaci smí číst zprávy, takže by je synchronizace doručovala (spustila by lhůty). Použijte uživatele jen s právem „Zobrazovat seznamy, historii a doručenky“, nebo doručování synchronizací v nastavení výslovně povolte.',
            'blocked_no_list' => 'Přihlášení pro synchronizaci nemá právo „Zobrazovat seznamy, historii a doručenky“, seznam zpráv nelze načíst.',
            'login_failed' => 'Přihlášení pro synchronizaci bylo odmítnuto. Zadejte správné údaje v nastavení datových schránek.',
            'no_credential' => 'Schránka nemá uložené přihlášení pro synchronizaci.',
            default => 'Oprávnění přihlášení pro synchronizaci se nepodařilo zjistit.',
        };
    }

    /** First run after the login was stored without privileges: learn them before deciding anything. @param array<string,mixed> $box @return array<string,mixed> */
    private function ensureVerified(array $box): array
    {
        if (($box['credentials']['sync'] ?? null) !== null && $this->service->privilegesOf($box['credentials']['sync']) === null) {
            $this->service->refreshPrivileges($box, 'sync');
            return $this->boxes->find((int) $box['id']) ?? $box;
        }
        return $box;
    }

    /**
     * All envelopes of one direction in [from, to], read in 40-day chunks so a response never hits the page limit
     * for an ordinary box; a full page is followed by the next offset.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function window(IsdsAccess $access, string $direction, \DateTimeImmutable $from, \DateTimeImmutable $to): \Generator
    {
        for ($start = $from; $start < $to; $start = $start->modify('+' . self::CHUNK_DAYS . ' days')) {
            // The chunks overlap by two minutes (WS spec 2.9.1), the upsert absorbs the duplicates.
            $end = min($start->modify('+' . self::CHUNK_DAYS . ' days'), $to)->modify('+2 minutes');
            $offset = 1;
            do {
                $page = $direction === 'received'
                    ? $this->isds->listReceived($access, $start, $end, -1, $offset, self::PAGE)
                    : $this->isds->listSent($access, $start, $end, -1, $offset, self::PAGE);
                foreach ($page as $envelope) {
                    if (!empty($envelope['dm_id'])) {
                        yield $envelope;
                    }
                }
                $offset += self::PAGE;
            } while (count($page) === self::PAGE);
        }
    }
}
