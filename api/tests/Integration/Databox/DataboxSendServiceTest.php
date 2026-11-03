<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxSendService;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

final class DataboxSendServiceTest extends DataboxIntegrationTestCase
{
    private const PDF = ['name' => 'dopis.pdf', 'bytes' => "%PDF-1.4 synthetic\n"];

    /** @return array<string,mixed> a box with a list-only sync login and a login that may send + search */
    private function senderBox(): array
    {
        return $this->connectBox('user_info_list_only', 'user_info_sender');
    }

    /** @return array{recipient_box_id:string, annotation:string} */
    private function envelope(string $recipient = DataboxFixtures::TAX_OFFICE_PRAHA): array
    {
        return ['recipient_box_id' => $recipient, 'annotation' => 'Dotaz k DPH'];
    }

    private function scriptSend(): void
    {
        $this->isds->queue('CreateMessage', DataboxFixtures::xml('create_message_ok'));
        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'sealed-receipt'));
        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', DataboxFixtures::zfo('4102002', [['name' => self::PDF['name'], 'mime' => 'application/pdf', 'bytes' => self::PDF['bytes']]])));
    }

    public function testPrepareStagesThePayloadAndSendsNothing(): void
    {
        $box = $this->senderBox();
        $this->forgetOperations();

        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $prepared['token']);
        $s = $prepared['summary'];
        self::assertSame('7nyn2d9', $s['recipient']['box_id']);
        self::assertSame('Finanční úřad pro hlavní město Prahu', $s['recipient']['name'], 'the tax office is known from the codebook');
        self::assertTrue($s['recipient']['is_tax_office']);
        self::assertSame('tpbxbx7', $s['box']['db_id']);
        self::assertSame('dopis.pdf', $s['files'][0]['name']);
        self::assertSame('application/pdf', $s['files'][0]['mime']);
        self::assertSame(hash('sha256', self::PDF['bytes']), $s['files'][0]['sha256']);
        self::assertSame([], $this->isds->requests, 'prepare never talks to ISDS (a known tax office needs no lookup)');
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM databox_messages WHERE databox_id = {$box['id']} AND direction = 'sent'")->fetchColumn());
    }

    public function testAnUnknownRecipientIsLookedUpWhenALoginMaySearch(): void
    {
        $box = $this->senderBox();
        $this->forgetOperations();
        $this->isds->queue('FindDataBox2', DataboxFixtures::xml('find_databox_found'));
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope('kd4n6w3'), [self::PDF]);
        self::assertSame(['FindDataBox2'], $this->isds->operations());
        self::assertTrue($prepared['summary']['recipient']['verified']);

        $this->isds->queue('FindDataBox2', DataboxFixtures::xml('find_databox_none'));
        $unverified = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope('q7m2zpk'), [self::PDF]);
        self::assertFalse($unverified['summary']['recipient']['verified']);
        self::assertNull($unverified['summary']['recipient']['name']);
    }

    public function testSendingRequiresTheExplicitConfirmation(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->forgetOperations();

        foreach ([false] as $confirmed) {
            try {
                $this->sender->send($this->supplierId, $this->userId, $prepared['token'], $confirmed);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame('confirmation_required', $e->errorCode);
            }
        }
        self::assertSame([], $this->isds->requests, 'nothing is sent without the confirmation');
        self::assertNotNull($this->outbox->peek($prepared['token'], new \DateTimeImmutable()), 'and the staged payload is still there for the real confirmation');
    }

    public function testConfirmedSendCreatesTheMessageStoresItAndItsReceipt(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope() + ['sender_ref_number' => 'ČJ 77'], [self::PDF, ['name' => 'priloha.txt', 'bytes' => 'hello']]);
        $this->forgetOperations();
        $this->scriptSend();

        $result = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);

        self::assertSame('4102002', $result['dm_id']);
        self::assertTrue($result['receipt']);
        self::assertTrue($result['zfo']);
        self::assertSame(['CreateMessage', 'GetDeliveryInfo', 'GetSignedDeliveryInfo', 'SignedSentMessageDownload'], $this->isds->operations());
        self::assertSame('action-user', $this->isds->requests[0]['username'], 'the login that has the send right');

        $body = $this->isds->bodyOf('CreateMessage');
        self::assertStringContainsString('<p:dbIDRecipient>7nyn2d9</p:dbIDRecipient>', $body);
        self::assertStringContainsString('<p:dmAnnotation>Dotaz k DPH</p:dmAnnotation>', $body);
        self::assertStringContainsString('<p:dmSenderRefNumber>ČJ 77</p:dmSenderRefNumber>', $body);
        self::assertStringContainsString(base64_encode(self::PDF['bytes']), $body);
        self::assertStringContainsString('dmFileMetaType="main" dmFileDescr="dopis.pdf"', str_replace(' dmMimeType="application/pdf"', '', $body));

        $sent = $this->messages->find($result['message_id']);
        self::assertSame('sent', $sent['direction']);
        self::assertSame('4102002', $sent['dm_id']);
        self::assertSame('tpbxbx7', $sent['sender_box_id']);
        self::assertSame('7nyn2d9', $sent['recipient_box_id']);
        self::assertSame('Dotaz k DPH', $sent['annotation']);
        self::assertSame(6, $sent['status'], 'status taken from the delivery receipt');
        self::assertTrue($sent['has_delivery_info']);
        self::assertTrue($sent['has_delivery_zfo']);
        self::assertTrue($sent['has_zfo']);
        self::assertNotNull($sent['acknowledged_at']);
        self::assertSame($this->userId, $sent['sent_by']);
        self::assertSame(['dopis.pdf', 'priloha.txt'], array_column($sent['sent_payload']['files'], 'name'));
        self::assertSame(hash('sha256', 'hello'), $sent['sent_payload']['files'][1]['sha256']);
    }

    public function testATokenWorksExactlyOnce(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->scriptSend();
        $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
        $this->forgetOperations();

        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('token_invalid', $e->errorCode);
        }
        self::assertSame(0, $this->isds->count('CreateMessage'), 'a second click cannot send a second message');
    }

    public function testATokenBelongsToTheUserWhoPreparedIt(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->forgetOperations();
        $this->expectException(DataboxException::class);
        $this->expectExceptionMessage('neplatné');
        $this->sender->send($this->supplierId, $this->userId + 9999, $prepared['token'], true);
    }

    public function testATokenOfAnotherSupplierOrAForgedOneIsRejected(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        foreach ([[$this->supplierId + 999, $prepared['token']], [$this->supplierId, str_repeat('a', 32)], [$this->supplierId, 'not-a-token']] as [$supplier, $token]) {
            try {
                $this->sender->send($supplier, $this->userId, $token, true);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame('token_invalid', $e->errorCode);
            }
        }
        self::assertSame(0, $this->isds->count('CreateMessage'));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $box = $this->senderBox();
        $now = new \DateTimeImmutable('2026-11-03 10:00:00');
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF], null, [], $now);
        self::assertSame('2026-11-03 10:30:00', $prepared['expires_at']);

        $this->expectException(DataboxException::class);
        $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, false, $now->modify('+31 minutes'));
    }

    public function testAnAmbiguousFailureConsumesTheTokenAndWarnsToCheckTheSentList(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->isds->queue('CreateMessage', 'gateway timeout', 504);

        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('server_error', $e->reason);
            self::assertStringContainsString('mohla být přesto odeslána', $e->getMessage());
        }
        self::assertNull($this->outbox->peek($prepared['token'], new \DateTimeImmutable()), 'no automatic retry with the same payload');
        $this->expectException(DataboxException::class);
        $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
    }

    public function testAnIsdsRefusalIsReportedAsIs(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->isds->queue('CreateMessage', str_replace(
            ['<q:dmID>4102002</q:dmID>', '<q:dmStatusCode>0000</q:dmStatusCode>'],
            ['', '<q:dmStatusCode>1252</q:dmStatusCode>'],
            DataboxFixtures::xml('create_message_ok'),
        ));
        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('invalid_input', $e->reason);
            self::assertSame('1252', $e->isdsCode);
        }
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM databox_messages WHERE databox_id = {$box['id']}")->fetchColumn(), 'a refused message leaves no record');
    }

    public function testASentMessageSurvivesWhenTheReceiptAndSealedCopyAreNotAvailableYet(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
        $this->isds->queue('CreateMessage', DataboxFixtures::xml('create_message_ok'));
        $this->isds->queue('GetDeliveryInfo', 'busy', 503);
        $this->isds->queue('SignedSentMessageDownload', 'busy', 503);

        $result = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);

        self::assertFalse($result['receipt']);
        self::assertFalse($result['zfo']);
        $sent = $this->messages->find($result['message_id']);
        self::assertSame('4102002', $sent['dm_id']);
        self::assertNull($sent['status']);
    }

    /** @return array<string, array{array<string,mixed>, list<array{name:string, bytes:string}>, string}> */
    public static function invalidInput(): array
    {
        $ok = self::PDF;
        return [
            'recipient with a wrong check digit' => [['recipient_box_id' => '7nyn2d8', 'annotation' => 'x'], [$ok], 'ID datové schránky příjemce'],
            'own box as the recipient' => [['recipient_box_id' => 'tpbxbx7', 'annotation' => 'x'], [$ok], 'vlastní datové schránky'],
            'empty annotation' => [['recipient_box_id' => '7nyn2d9', 'annotation' => " \n "], [$ok], 'Vyplňte věc'],
            'annotation over 255 characters' => [['recipient_box_id' => '7nyn2d9', 'annotation' => str_repeat('ř', 256)], [$ok], '255 znaků'],
            'no attachment' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [], 'alespoň jednu přílohu'],
            'empty attachment' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [['name' => 'a.pdf', 'bytes' => '']], 'je prázdná'],
            'a forbidden extension' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [['name' => 'virus.exe', 'bytes' => 'MZ']], 'není povolená'],
            'broken XML' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [['name' => 'a.xml', 'bytes' => '<a>']], 'není platné XML'],
            'duplicate names' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [$ok, $ok], 'použit dvakrát'],
            'over 20 MB' => [['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [['name' => 'big.pdf', 'bytes' => str_repeat('a', DataboxSendService::MAX_TOTAL_BYTES + 1)]], 'přesahují 20 MB'],
        ];
    }

    /**
     * @param array<string,mixed> $envelope
     * @param list<array{name:string, bytes:string}> $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputIsRejectedAtPrepare(array $envelope, array $files, string $messagePart): void
    {
        $box = $this->senderBox();
        $this->forgetOperations();
        try {
            $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $envelope, $files);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
            self::assertSame([], $this->isds->requests);
        }
    }

    public function testPathsInAttachmentNamesAreStrippedAndAnnotationIsOneLine(): void
    {
        $box = $this->senderBox();
        $prepared = $this->sender->prepare(
            $this->supplierId,
            $this->userId,
            $box['id'],
            ['recipient_box_id' => '7nyn2d9', 'annotation' => "Řádek 1\r\nŘádek 2\t konec"],
            [['name' => '../../etc/passwd.txt', 'bytes' => 'x'], ['name' => 'C:\\temp\\a<b>.pdf', 'bytes' => 'y']],
        );
        self::assertSame('Řádek 1 Řádek 2 konec', $prepared['summary']['annotation']);
        self::assertSame(['passwd.txt', 'a_b_.pdf'], array_column($prepared['summary']['files'], 'name'));
    }

    public function testALoginThatCannotSendIsRefusedAtPrepare(): void
    {
        $box = $this->connectBox('user_info_list_only');
        try {
            $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('missing_right', $e->errorCode);
            self::assertStringContainsString('Posílat zprávy a číst odeslané', $e->getMessage());
        }
    }

    public function testAnInactiveBoxCannotSend(): void
    {
        $box = $this->senderBox();
        $this->service->update($this->supplierId, $box['id'], ['is_active' => false]);
        $this->expectException(DataboxException::class);
        $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF]);
    }

    public function testExpiredStagedPayloadsAreCleanedUp(): void
    {
        $box = $this->senderBox();
        $old = new \DateTimeImmutable('2026-01-01 10:00:00');
        $stale = $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF], null, [], $old);
        $this->sender->prepare($this->supplierId, $this->userId, $box['id'], $this->envelope(), [self::PDF], null, [], new \DateTimeImmutable('2026-11-03 10:00:00'));

        self::assertSame(0, $this->countOutbox($stale['token']), 'a later prepare purges the expired payloads');
    }

    private function countOutbox(string $token): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM databox_outbox WHERE token = ?');
        $stmt->execute([$token]);
        return (int) $stmt->fetchColumn();
    }
}
