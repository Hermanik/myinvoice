<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * ISDS locks an account after a few wrong passwords. Whatever code path meets a refused login, the stored login is
 * flagged (sticky), nothing else tries it again, and a manual retry has to wait (5 min, doubling).
 */
final class DataboxLockoutTest extends DataboxIntegrationTestCase
{
    private const PDF = ['name' => 'dopis.pdf', 'bytes' => "%PDF-1.4 synthetic\n"];

    private function badPassword(string $operation): void
    {
        $this->isds->queue($operation, (string) file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);
    }

    /** @return array<string,mixed> the stored login of a purpose */
    private function credential(int $boxId, string $purpose): array
    {
        return $this->storedBox($boxId)['credentials'][$purpose];
    }

    /** A box with a list-only sync login and a reading action login, synced once. @return array{box:array<string,mixed>, tax:int} */
    private function boxWithMessages(): array
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_reader');
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $this->forgetOperations();
        return ['box' => $box, 'tax' => $this->messages->findByDmId($box['id'], 'received', '4101001')['id']];
    }

    private function assertFlagged(int $boxId, string $purpose): void
    {
        $cred = $this->credential($boxId, $purpose);
        self::assertSame('bad_credentials', $cred['last_check_status']);
        self::assertSame(1, (int) $cred['auth_failures']);
        self::assertGreaterThan(time(), strtotime((string) $cred['retry_after']), 'a manual retry has to wait');
    }

    // ---- every code path flags the login ----

    public function testAnOpenThatMeetsAWrongPasswordFlagsTheActionLoginAndNothingTriesItAgain(): void
    {
        $ctx = $this->boxWithMessages();
        $this->badPassword('GetListOfReceivedMessages');

        try {
            $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
        }
        $this->assertFlagged($ctx['box']['id'], 'action');
        self::assertSame('ok', $this->credential($ctx['box']['id'], 'sync')['last_check_status'], 'the other login is untouched');

        $this->forgetOperations();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame('login_failed', $e->errorCode);
            }
        }
        self::assertSame([], $this->isds->requests, 'a refused password is not tried again - that is how accounts get locked');
        self::assertSame(1, (int) $this->credential($ctx['box']['id'], 'action')['auth_failures']);
    }

    public function testASendThatMeetsAWrongPasswordFlagsTheLoginAndTheNextSendNeverCallsIsds(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $first = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], ['recipient_box_id' => DataboxFixtures::TAX_OFFICE_PRAHA, 'annotation' => 'x'], [self::PDF]);
        $second = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], ['recipient_box_id' => DataboxFixtures::TAX_OFFICE_PRAHA, 'annotation' => 'y'], [self::PDF]);
        $this->forgetOperations();
        $this->badPassword('CreateMessage');

        try {
            $this->sender->send($this->supplierId, $this->userId, $first['token'], true);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
        }
        $this->assertFlagged($box['id'], 'action');

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $second['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('login_failed', $e->errorCode);
        }
        self::assertSame([], $this->isds->requests);
        self::assertNotNull($this->outbox->peek($second['token'], new \DateTimeImmutable()), 'refused before the payload was consumed');
    }

    public function testAReceiptRefreshThatMeetsAWrongPasswordFlagsTheLogin(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $sent = $this->messages->findByDmId($box['id'], 'sent', '4102001');
        $this->badPassword('GetDeliveryInfo');

        try {
            $this->messageService->refreshReceipt($this->supplierId, $sent['id']);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
        }
        $this->assertFlagged($box['id'], 'action');
    }

    public function testTheSyncCountsOneFailureNotTwo(): void
    {
        $box = $this->connectBox();
        $this->badPassword('GetUserInfoFromLogin2');
        $report = $this->sync->syncBox($this->storedBox($box['id']), new \DateTimeImmutable('2026-11-03 10:00:00'));

        self::assertSame('paused', $report['status']);
        $this->assertFlagged($box['id'], 'sync');
        self::assertSame('bad_credentials', $this->storedBox($box['id'])['credentials']['sync']['last_check_status']);
    }

    public function testALockedAccountWaitsAtLeastUntilItsBlockEnds(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetUserInfoFromLogin2', (string) file_get_contents(DataboxFixtures::path('http_401_locked.html')), 401);
        $this->sync->syncBox($this->storedBox($box['id']));

        $cred = $this->credential($box['id'], 'sync');
        self::assertSame('locked', $cred['last_check_status']);
        self::assertGreaterThanOrEqual(strtotime('+1 minute'), strtotime((string) $cred['retry_after']));
        self::assertGreaterThan(strtotime('+6 minutes'), strtotime((string) $cred['retry_after']), 'later than the plain 5 minutes: ISDS itself keeps the account blocked');
    }

    // ---- a flagged login is skipped ----

    public function testAFlaggedLoginIsSkippedForTheNextLoginWithTheRight(): void
    {
        // Both logins can read; the sync one only because the owner opted in.
        $box = $this->connectBox('user_info_reader', 'user_info_reader', 'owner_info_pfo', true);
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_empty'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $tax = $this->messages->findByDmId($box['id'], 'received', '4101001')['id'];
        $this->boxes->registerAuthFailure($box['id'], 'action', 'bad_credentials', 'refused', new \DateTimeImmutable());
        $this->forgetOperations();
        $this->isds->queue('GetListOfReceivedMessages', str_replace('<q:dmMessageStatus>4</q:dmMessageStatus>', '<q:dmMessageStatus>6</q:dmMessageStatus>', DataboxFixtures::xml('list_received')));
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::signedResponse('SignedMessageDownload', DataboxFixtures::zfo('4101001', [['name' => 'a.txt', 'mime' => 'text/plain', 'bytes' => 'x']])));

        $this->messageService->open($this->supplierId, $tax, $this->userId, true);

        self::assertSame(['sync-user', 'sync-user'], array_column($this->isds->requests, 'username'), 'the refused action login was never used');
    }

    public function testWhenEveryLoginWithTheRightIsFlaggedNothingIsCalled(): void
    {
        $ctx = $this->boxWithMessages();
        $this->boxes->registerAuthFailure($ctx['box']['id'], 'action', 'bad_credentials', 'refused', new \DateTimeImmutable());

        try {
            $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('login_failed', $e->errorCode, 'not "missing right": the right exists, the password is refused');
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testAFailedVerifyThatSaysNothingAboutThePasswordKeepsTheFlag(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        $this->isds->queue('GetOwnerInfoFromLogin2', 'maintenance', 503);

        $verified = $this->service->verify($this->supplierId, $box['id'], $now->modify('+6 minutes'));

        self::assertSame('maintenance', $verified['check']['sync']['reason']);
        self::assertSame('bad_credentials', $this->credential($box['id'], 'sync')['last_check_status'], 'an outage does not make a refused password usable again');
        self::assertSame(1, (int) $this->credential($box['id'], 'sync')['auth_failures']);
    }

    public function testASuccessfulVerifyLiftsTheFlag(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        self::assertTrue($this->service->isFlagged($this->credential($box['id'], 'sync')));
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');

        $verified = $this->service->verify($this->supplierId, $box['id'], $now->modify('+6 minutes'));

        self::assertTrue($verified['check']['sync']['ok']);
        $cred = $this->credential($box['id'], 'sync');
        self::assertSame('ok', $cred['last_check_status']);
        self::assertSame(0, (int) $cred['auth_failures']);
        self::assertNull($cred['retry_after']);
    }

    // ---- the wait before a manual retry ----

    public function testVerifyDuringTheWaitDoesNotCallIsds(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        $this->forgetOperations();

        $verified = $this->service->verify($this->supplierId, $box['id'], $now->modify('+4 minutes'));

        self::assertFalse($verified['check']['sync']['ok']);
        self::assertSame('cooldown', $verified['check']['sync']['reason']);
        self::assertStringContainsString('Další pokus je možný po', $verified['check']['sync']['message']);
        self::assertSame([], $this->isds->requests);
        self::assertSame(1, (int) $this->credential($box['id'], 'sync')['auth_failures'], 'waiting is not an attempt');
    }

    public function testEachFailedRetryDoublesTheWaitUpToADay(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable('2026-11-03 10:00:00');
        $waits = [];
        for ($i = 0; $i < 14; $i++) {
            $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
            $waits[] = strtotime((string) $this->credential($box['id'], 'sync')['retry_after']) - $now->getTimestamp();
        }
        self::assertSame([300, 600, 1200, 2400], array_slice($waits, 0, 4));
        self::assertSame(86400, max($waits), 'capped at 24 hours');
        self::assertSame(86400, end($waits));
    }

    public function testAnActualRetryAfterTheWaitIsAllowedAndFailingAgainWaitsLonger(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        $later = $now->modify('+6 minutes');
        $this->badPassword('GetOwnerInfoFromLogin2');

        $verified = $this->service->verify($this->supplierId, $box['id'], $later);

        self::assertSame('bad_credentials', $verified['check']['sync']['reason']);
        $cred = $this->credential($box['id'], 'sync');
        self::assertSame(2, (int) $cred['auth_failures']);
        self::assertGreaterThan(strtotime('+9 minutes'), strtotime((string) $cred['retry_after']), 'the second wait is 10 minutes');
    }

    public function testReplacingThePasswordDuringTheWaitIsRefusedBeforeAnyCall(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        $this->forgetOperations();

        try {
            $this->service->setCredential($this->supplierId, $box['id'], 'sync', 'sync-user', 'another-guess', $now->modify('+1 minute'));
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('cooldown', $e->errorCode);
            self::assertSame(429, $e->httpStatus);
        }
        self::assertSame([], $this->isds->requests);

        // After the wait the replacement is tried; a refused one counts and extends the wait.
        $this->badPassword('GetOwnerInfoFromLogin2');
        try {
            $this->service->setCredential($this->supplierId, $box['id'], 'sync', 'sync-user', 'another-guess', $now->modify('+6 minutes'));
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
        }
        self::assertSame(2, (int) $this->credential($box['id'], 'sync')['auth_failures']);
        self::assertSame('bad_credentials', $this->credential($box['id'], 'sync')['last_check_status'], 'the stored login stays flagged');
    }

    public function testACorrectReplacementClearsTheFlagAndTheWait(): void
    {
        $box = $this->connectBox();
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure($box['id'], 'sync', 'bad_credentials', 'refused', $now);
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');

        $this->service->setCredential($this->supplierId, $box['id'], 'sync', 'sync-user', 'the-right-password', $now->modify('+6 minutes'));

        $cred = $this->credential($box['id'], 'sync');
        self::assertSame('ok', $cred['last_check_status']);
        self::assertNull($cred['retry_after']);
        self::assertSame(0, (int) $cred['auth_failures']);
    }
}
