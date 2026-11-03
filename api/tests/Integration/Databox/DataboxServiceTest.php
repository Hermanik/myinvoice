<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxPrivileges;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

final class DataboxServiceTest extends DataboxIntegrationTestCase
{
    public function testBoxIdTypeAndNameComeFromTheLoginAndThePasswordIsEncrypted(): void
    {
        $box = $this->connectBox();

        self::assertSame('tpbxbx7', $box['db_id'], 'resolved by GetOwnerInfoFromLogin2, not typed by the user');
        self::assertSame('PFO', $box['db_type']);
        self::assertSame('Jan Testovací - IT služby', $box['owner_name']);
        self::assertSame('production', $box['environment']);
        self::assertSame(8, $box['credentials']['sync']['privileges']);
        self::assertFalse($box['credentials']['sync']['can_read']);
        self::assertTrue($box['credentials']['sync']['can_list']);
        self::assertSame('ok', $box['credentials']['sync']['last_check_status']);
        self::assertSame('2027-01-15 10:30:00', $box['credentials']['sync']['password_expires_at']);
        self::assertSame(['state' => 'ok', 'delivers' => false], $box['sync']);

        // Encryption round trip with the application's existing secret encryption.
        $stored = $this->storedBox($box['id'])['credentials']['sync'];
        self::assertStringStartsWith('enc:v1:', $stored['password_enc']);
        self::assertStringNotContainsString('S3cret-sync-pass', $stored['password_enc']);
        self::assertSame('S3cret-sync-pass', $this->crypto->decrypt($stored['password_enc']));

        $access = $this->service->accessFor($this->storedBox($box['id']), 'sync');
        self::assertSame('sync-user', $access->credentials->username);
        self::assertSame('S3cret-sync-pass', $access->credentials->password());
    }

    public function testNoSecretIsEverPartOfThePublicView(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $json = json_encode($box, JSON_THROW_ON_ERROR);

        foreach (['S3cret-sync-pass', 'S3cret-action-pass', 'password_enc', 'enc:v1:'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $this->assertNoKeyNamedPassword($box);
        self::assertArrayHasKey('action', $box['credentials']);
    }

    /** @param array<string,mixed> $data */
    private function assertNoKeyNamedPassword(array $data): void
    {
        foreach ($data as $key => $value) {
            self::assertNotSame('password', $key);
            self::assertNotSame('password_enc', $key);
            if (is_array($value)) {
                $this->assertNoKeyNamedPassword($value);
            }
        }
    }

    public function testALoginThatCanReadIsBlockedForTheSyncUntilTheOwnerOptsIn(): void
    {
        $box = $this->connectBox('user_info_reader');
        self::assertSame(['state' => 'blocked_delivers', 'delivers' => true], $box['sync']);

        $opted = $this->service->update($this->supplierId, $box['id'], ['allow_delivering_sync' => true]);
        self::assertSame(['state' => 'opt_in', 'delivers' => true], $opted['sync']);

        $back = $this->service->update($this->supplierId, $box['id'], ['allow_delivering_sync' => false]);
        self::assertSame('blocked_delivers', $back['sync']['state']);
    }

    public function testChangingTheOptInDropsTheStoredRefusal(): void
    {
        $box = $this->connectBox('user_info_reader');
        $this->sync->syncBox($this->storedBox($box['id']));
        self::assertSame('paused', $this->storedBox($box['id'])['last_sync_status'], 'the refused run was recorded');

        $this->service->update($this->supplierId, $box['id'], ['allow_delivering_sync' => true]);
        $stored = $this->storedBox($box['id']);
        self::assertNull($stored['last_sync_status'], 'the old refusal no longer describes the setting');
        self::assertNull($stored['last_sync_message']);
    }

    public function testTheOwnersOwnLoginIsAlwaysTreatedAsDelivering(): void
    {
        $box = $this->connectBox('user_info_primary');
        self::assertSame(255, $box['credentials']['sync']['privileges']);
        self::assertSame('blocked_delivers', $box['sync']['state']);
    }

    public function testALoginWithoutTheListRightCannotSync(): void
    {
        $box = $this->connectBox('user_info_send_only');
        self::assertSame(4, $box['credentials']['sync']['privileges']);
        self::assertSame(['state' => 'blocked_no_list', 'delivers' => false], $box['sync']);
    }

    public function testAnActionLoginThatCannotReadIsSafeForTheSyncToo(): void
    {
        $box = $this->connectBox('user_info_sender');
        self::assertSame(['state' => 'ok', 'delivers' => false], $box['sync'], 'list + send + search can list and cannot read');
    }

    public function testWrongPasswordIsRejectedAndNothingIsStored(): void
    {
        $before = count($this->service->listPublic($this->supplierId));
        $this->isds->queue('GetOwnerInfoFromLogin2', file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);
        try {
            $this->service->add($this->supplierId, ['sync' => ['username' => 'u', 'password' => 'wrong']]);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
            self::assertStringNotContainsString('wrong', $e->getMessage());
        }
        self::assertCount($before, $this->service->listPublic($this->supplierId));
    }

    public function testTwoLoginsMustBelongToTheSameBox(): void
    {
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');
        $this->scriptLogin('owner_info_fo', 'user_info_sender');
        try {
            $this->service->add($this->supplierId, [
                'sync' => ['username' => 'a', 'password' => 'a'],
                'action' => ['username' => 'b', 'password' => 'b'],
            ]);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('box_mismatch', $e->errorCode);
        }
    }

    public function testTheSameBoxCannotBeConnectedTwice(): void
    {
        $this->connectBox();
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');
        try {
            $this->service->add($this->supplierId, ['sync' => ['username' => 'u', 'password' => 'p']]);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('duplicate', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testTwoBoxesOfTheOwnerCanLiveSideBySide(): void
    {
        $pfo = $this->connectBox();
        $fo = $this->connectBox('user_info_list_only', null, 'owner_info_fo');
        self::assertSame(['PFO', 'FO'], [$pfo['db_type'], $fo['db_type']]);
        $types = array_column(array_filter($this->service->listPublic($this->supplierId), static fn (array $b): bool => $b['label'] === 'Test box'), 'db_type');
        self::assertEqualsCanonicalizing(['PFO', 'FO'], $types);
    }

    public function testBoxesBelongToTheirSupplier(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has only one supplier.');
        }
        $box = $this->connectBox();
        $this->expectException(DataboxException::class);
        $this->service->requireBox($this->otherSupplierId, $box['id']);
    }

    public function testVerifyRefreshesPrivilegesAfterTheOwnerChangedThem(): void
    {
        $box = $this->connectBox('user_info_list_only');
        self::assertSame('ok', $box['sync']['state']);

        // The owner later granted the read right in the ISDS portal.
        $this->scriptLogin('owner_info_pfo', 'user_info_reader');
        $verified = $this->service->verify($this->supplierId, $box['id']);

        self::assertSame(1 + 4 + 8 + 16, $verified['credentials']['sync']['privileges']);
        self::assertSame('blocked_delivers', $verified['sync']['state'], 'the sync is switched off the moment the login can read');
        self::assertTrue($verified['check']['sync']['ok']);
    }

    public function testVerifyReportsAFailedLoginWithoutLosingTheBox(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetOwnerInfoFromLogin2', file_get_contents(DataboxFixtures::path('http_401_locked.html')), 401);
        $verified = $this->service->verify($this->supplierId, $box['id']);

        self::assertFalse($verified['check']['sync']['ok']);
        self::assertSame('account_locked', $verified['check']['sync']['reason']);
        self::assertSame('locked', $verified['credentials']['sync']['last_check_status']);
        self::assertSame('login_failed', $verified['sync']['state']);
    }

    public function testReplacingACredentialRequiresTheSameBox(): void
    {
        $box = $this->connectBox();
        $this->scriptLogin('owner_info_fo', 'user_info_list_only');
        try {
            $this->service->setCredential($this->supplierId, $box['id'], 'sync', 'x', 'y');
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('box_mismatch', $e->errorCode);
        }

        $this->scriptLogin('owner_info_pfo', 'user_info_sender');
        $updated = $this->service->setCredential($this->supplierId, $box['id'], 'action', 'new-action', 'S3cret-new');
        self::assertSame('new-action', $updated['credentials']['action']['username']);
        self::assertSame('S3cret-new', $this->crypto->decrypt($this->storedBox($box['id'])['credentials']['action']['password_enc']));
    }

    public function testRemovingABoxWithStoredMessagesNeedsConfirmation(): void
    {
        $box = $this->connectBox();
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_empty'));
        $this->sync->syncBox($this->storedBox($box['id']));

        try {
            $this->service->remove($this->supplierId, $box['id'], false);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('confirm_required', $e->errorCode);
            self::assertSame(3, $e->extra['message_count']);
        }
        self::assertNotNull($this->boxes->find($box['id']));

        $this->service->remove($this->supplierId, $box['id'], true);
        self::assertNull($this->boxes->find($box['id']));
    }

    public function testTheLoginForAnActionIsChosenByTheRightItNeeds(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $stored = $this->storedBox($box['id']);

        $send = $this->service->accessForRight($stored, static fn (DataboxPrivileges $p): bool => $p->canSend(), 'Posílat zprávy');
        self::assertSame('action-user', $send->credentials->username);

        $list = $this->service->accessForRight($stored, static fn (DataboxPrivileges $p): bool => $p->canList(), 'Zobrazovat seznamy');
        self::assertSame('action-user', $list->credentials->username, 'the action login is preferred when it has the right');

        try {
            $this->service->accessForRight($stored, static fn (DataboxPrivileges $p): bool => $p->canReadReceived(), 'Číst přijaté zprávy');
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('missing_right', $e->errorCode);
            self::assertSame('Číst přijaté zprávy', $e->extra['missing_right']);
        }
    }
}
