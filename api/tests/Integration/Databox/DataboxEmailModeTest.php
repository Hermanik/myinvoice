<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxPrivileges;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * A box that is watched through notification e-mails only: how it is connected, that the ISDS synchronisation leaves it
 * alone and that every action that would need a login is refused before ISDS is asked anything.
 */
final class DataboxEmailModeTest extends DataboxIntegrationTestCase
{
    private function messageOf(array $box): int
    {
        return $this->messages->insertFromEmailNotice($box['id'], [
            'dm_id' => F::DM_ID, 'sender_box_id' => F::SENDER_BOX, 'sender_name' => 'Úřad', 'sender_type' => null,
            'recipient_box_id' => $box['db_id'], 'recipient_name' => 'Jana', 'annotation' => 'Věc',
            'delivery_time' => new \DateTimeImmutable('2026-11-09 09:30:00'),
        ], new \DateTimeImmutable('2026-11-09 10:00:00'))['id'];
    }

    private function assertRefused(callable $action, string $what): void
    {
        try {
            $action();
        } catch (DataboxException $e) {
            self::assertSame('email_only', $e->errorCode, $what);
            self::assertSame(422, $e->httpStatus, $what);
            self::assertStringContainsString('e-mailová upozornění', $e->getMessage(), $what);
            return;
        }
        self::fail("{$what} was not refused");
    }

    public function testABoxIsConnectedByItsIdWithoutAnyLogin(): void
    {
        $box = $this->service->add($this->supplierId, [
            'mode' => 'email', 'label' => '  Moje schránka  ', 'db_id' => strtoupper(F::RECIPIENT_BOX), 'db_type' => 'pfo', 'owner_name' => 'Jana Testovací',
        ]);
        $this->trackBox($box['id']);

        self::assertSame('email', $box['connection_mode']);
        self::assertSame('Moje schránka', $box['label']);
        self::assertSame(F::RECIPIENT_BOX, $box['db_id'], 'normalised');
        self::assertSame('PFO', $box['db_type']);
        self::assertSame('Jana Testovací', $box['owner_name']);
        self::assertSame('production', $box['environment']);
        self::assertSame([], $box['credentials']);
        self::assertSame(['state' => 'email_only', 'delivers' => false], $box['sync']);
        self::assertSame([], $this->isds->requests, 'ISDS was not asked anything');
        self::assertCount(0, $this->db->pdo()->query('SELECT 1 FROM databox_credentials WHERE databox_id = ' . (int) $box['id'])->fetchAll());
    }

    public function testAnIsdsBoxStaysInTheDefaultMode(): void
    {
        $box = $this->connectBox();

        self::assertSame('isds', $box['connection_mode']);
        self::assertSame('isds', $this->storedBox($box['id'])['connection_mode']);
    }

    public function testTheLabelDefaultsToTheOwnerThenToTheBoxId(): void
    {
        $box = $this->service->add($this->supplierId, ['mode' => 'email', 'db_id' => F::RECIPIENT_BOX, 'db_type' => 'PO', 'owner_name' => 'Firma s.r.o.']);
        self::assertSame('Firma s.r.o.', $box['label']);
        $this->boxes->delete($box['id']);

        $bare = $this->service->add($this->supplierId, ['mode' => 'email', 'db_id' => F::RECIPIENT_BOX, 'db_type' => 'FO']);
        self::assertSame(F::RECIPIENT_BOX, $bare['label']);
        self::assertNull($bare['owner_name']);
        $this->boxes->delete($bare['id']);
    }

    public function testTheInputIsValidated(): void
    {
        $valid = ['mode' => 'email', 'db_id' => F::RECIPIENT_BOX, 'db_type' => 'PFO'];
        foreach ([
            'a box id without the check character' => ['db_id' => 'xyz9876'],
            'a short box id' => ['db_id' => 'xyz98'],
            'no box id' => ['db_id' => ''],
            'an unknown type' => ['db_type' => 'OVM'],
            'no type' => ['db_type' => ''],
            'the test system' => ['environment' => 'test'],
        ] as $case => $change) {
            try {
                $this->service->add($this->supplierId, $change + $valid);
                self::fail("{$case} was accepted");
            } catch (DataboxException $e) {
                self::assertSame('validation_failed', $e->errorCode, $case);
                self::assertSame(400, $e->httpStatus, $case);
            }
        }
        self::assertSame([], $this->boxes->listForSupplier($this->supplierId), 'nothing was stored');
    }

    public function testTheSameBoxCannotBeConnectedTwice(): void
    {
        $this->connectEmailBox();

        try {
            $this->service->add($this->supplierId, ['mode' => 'email', 'db_id' => F::RECIPIENT_BOX, 'db_type' => 'PFO']);
            self::fail('a duplicate was accepted');
        } catch (DataboxException $e) {
            self::assertSame('duplicate', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testTheSynchronisationSkipsTheBoxSilently(): void
    {
        $email = $this->connectEmailBox();
        $reports = $this->sync->syncAll(new \DateTimeImmutable('2026-11-09 10:00:00'));

        self::assertNotContains($email['id'], array_column($reports, 'box_id'), 'not even a report');
        self::assertSame([], $this->isds->requests);
        self::assertNull($this->storedBox($email['id'])['last_sync_at']);

        $single = $this->sync->syncBox($this->storedBox($email['id']));
        self::assertSame('skipped', $single['status']);
        self::assertSame(0, $single['received_new']);
        self::assertSame([], $this->isds->requests);
        self::assertNull($this->storedBox($email['id'])['last_sync_status'], 'no sync status is written for it');
    }

    public function testTheSynchronisationStillRunsForAnIsdsBoxNextToIt(): void
    {
        $this->connectEmailBox();
        $isds = $this->connectBox();
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'sealed-receipt'));

        $reports = array_column($this->sync->syncAll(new \DateTimeImmutable('2026-11-03 10:00:00')), null, 'box_id');

        self::assertSame('ok', $reports[$isds['id']]['status']);
        self::assertCount(1, array_filter($reports, static fn (array $r): bool => $r['label'] === 'Test box' && $r['status'] === 'ok'));
    }

    public function testEveryIsdsActionOfTheBoxIsRefusedBeforeIsdsIsAsked(): void
    {
        $box = $this->connectEmailBox();
        $stored = $this->storedBox($box['id']);
        $messageId = $this->messageOf($box);
        $mid = $this->supplierId;

        $this->assertRefused(fn () => $this->service->verify($mid, $box['id']), 'verify');
        $this->assertRefused(fn () => $this->service->setCredential($mid, $box['id'], 'sync', 'user', 'S3cret-pass'), 'set a login');
        $this->assertRefused(fn () => $this->service->removeCredential($mid, $box['id'], 'action'), 'remove a login');
        $this->assertRefused(fn () => $this->service->update($mid, $box['id'], ['allow_delivering_sync' => true]), 'allow delivering sync');
        $this->assertRefused(fn () => $this->service->accessFor($stored, 'sync'), 'a login for the sync');
        $this->assertRefused(fn () => $this->service->accessForRight($stored, static fn (DataboxPrivileges $p): bool => $p->canList(), 'Zobrazovat seznamy'), 'a login for a right');
        $this->assertRefused(fn () => $this->service->refreshRights($stored, 'sync'), 'refresh rights');
        $this->assertRefused(fn () => $this->messageService->open($mid, $messageId, $this->userId, true), 'open and download a message');
        $this->assertRefused(fn () => $this->messageService->open($mid, $messageId, $this->userId, false), 'open without the acknowledgement');
        $this->assertRefused(fn () => $this->sender->prepare($mid, $this->userId, $box['id'], [
            'recipient_box_id' => DataboxFixtures::OWNER_FO, 'annotation' => 'Test',
        ], [['name' => 'a.txt', 'bytes' => 'x']]), 'send');

        self::assertSame([], $this->isds->requests, 'ISDS was never asked');
    }

    public function testAnEmailBoxIsNeverOfferedForSendingATaxFiling(): void
    {
        $email = $this->connectEmailBox();
        $isds = $this->connectBox('user_info_primary');
        $submissionId = $this->archiveSubmission('DPHDP3', self::epoXml());

        $prefill = $this->submissions->prefill($this->supplierId, $submissionId);

        self::assertSame([$isds['id']], array_column($prefill['boxes'], 'id'));
        self::assertNotSame($email['id'], $prefill['recommended_box_id']);
    }

    public function testTheDetailTellsThatTheMessageCanOnlyBeOpenedInThePortal(): void
    {
        $box = $this->connectEmailBox();
        $id = $this->messageOf($box);

        $detail = $this->messageService->detail($this->supplierId, $id);

        self::assertTrue($detail['open']['email_only']);
        self::assertFalse($detail['open']['stored']);
        self::assertSame('email', $detail['source']);
        self::assertSame('in_box', $detail['state']);
        self::assertSame('2026-11-19', $detail['open']['fiction_date']);
    }

    public function testRemovingTheBoxRemovesItsMessagesAndKeepsTheLog(): void
    {
        $box = $this->connectEmailBox();
        $id = $this->messageOf($box);
        $this->db->pdo()->prepare(
            "INSERT INTO databox_email_notices (supplier_id, dedup_key, status, databox_message_id) VALUES (?, ?, 'linked', ?)"
        )->execute([$this->supplierId, hash('sha256', 'removal-test'), $id]);

        try {
            $this->service->remove($this->supplierId, $box['id'], true);

            $row = $this->db->pdo()->prepare('SELECT databox_message_id FROM databox_email_notices WHERE supplier_id = ? AND dedup_key = ?');
            $row->execute([$this->supplierId, hash('sha256', 'removal-test')]);
            self::assertNull($row->fetch(\PDO::FETCH_ASSOC)['databox_message_id'], 'the log row outlives the message');
        } finally {
            $this->db->pdo()->prepare('DELETE FROM databox_email_notices WHERE supplier_id = ? AND dedup_key = ?')->execute([$this->supplierId, hash('sha256', 'removal-test')]);
        }
    }
}
