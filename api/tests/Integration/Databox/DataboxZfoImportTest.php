<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxZfoImportService;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;
use MyInvoice\Tests\Support\Databox\ZfoFixtures as Z;

/**
 * "Nahrát ZFO": signed messages and delivery receipts (synthetic, sealed with a throwaway key) are verified and merged into
 * the boxes. Boxes of every connection mode accept them; the data of the signed file is authoritative.
 */
final class DataboxZfoImportTest extends DataboxIntegrationTestCase
{
    private const OWN_BOX = F::RECIPIENT_BOX;
    private const SIGNER = 'Synthetic ISDS Sealer (Synthetic Operator)';

    private string $thirdParty;
    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thirdParty = Z::boxId('tstsnd');
        $this->foreign = Z::boxId('tstfrg');
    }

    /** A signed received message. @param array<string,mixed> $o */
    private function received(array $o = []): string
    {
        return Z::seal(Z::messageXml($o + ['sender' => $this->thirdParty, 'recipient' => self::OWN_BOX, 'sender_name' => 'Finanční úřad pro Testovací kraj']));
    }

    /** @return array<string,mixed> the result of the single file */
    private function importOne(string $bytes, string $name = 'zprava.zfo'): array
    {
        return $this->zfoImport->import($this->supplierId, $this->userId, [['name' => $name, 'bytes' => $bytes]])[0];
    }

    /** @return array<string,mixed>|null */
    private function stored(int $boxId, string $dmId, string $direction = 'received'): ?array
    {
        return $this->messages->findByDmId($boxId, $direction, $dmId);
    }

    // ---- received messages ----

    public function testADeliveredReceivedMessageIsStoredWithItsAttachmentsAndIsNotNews(): void
    {
        $box = $this->connectEmailBox();
        $unreadBefore = $this->messages->unreadSummary($this->supplierId);

        $result = $this->importOne($this->received(['files' => [['name' => 'vyzva.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-synthetic']]]));

        self::assertSame('created', $result['outcome'], $result['reason']);
        self::assertSame('message', $result['kind']);
        self::assertSame('received', $result['direction']);
        self::assertSame($box['id'], $result['databox_id']);
        self::assertSame(self::SIGNER, $result['signer']);
        self::assertSame('8800001', $result['dm_id']);

        $message = $this->stored($box['id'], '8800001');
        self::assertSame($result['message_id'], $message['id']);
        self::assertSame('zfo', $this->sourceOf($message['id']));
        self::assertSame(6, $message['status']);
        self::assertSame('2026-10-20 10:00:00', $message['delivery_time']);
        self::assertSame('2026-10-20 10:00:05', $message['acceptance_time']);
        self::assertSame($this->thirdParty, $message['sender_box_id']);
        self::assertSame(self::OWN_BOX, $message['recipient_box_id']);
        self::assertSame('Synthetic subject', $message['annotation']);
        self::assertTrue($message['has_zfo']);
        self::assertTrue($message['is_tax_office'], 'the tax office classification applies to an imported message');
        self::assertNotNull($message['acknowledged_at'], 'an already delivered message is history, not news');

        $unread = $this->messages->unreadSummary($this->supplierId);
        self::assertSame($unreadBefore['unread'], $unread['unread']);
        self::assertSame($unreadBefore['tax_office'], $unread['tax_office']);

        // The detail panel lists and serves the attachments of the stored ZFO.
        $detail = $this->messageService->detail($this->supplierId, $message['id']);
        self::assertSame('delivered', $detail['state']);
        self::assertCount(1, $detail['attachments']);
        self::assertSame('vyzva.pdf', $detail['attachments'][0]['name']);
        self::assertSame('%PDF-synthetic', $this->messageService->attachment($this->supplierId, $message['id'], 0)['bytes']);
        self::assertSame([], $this->isds->requests, 'the import never talks to ISDS');
    }

    public function testAStillUndeliveredReceivedMessageStaysUnread(): void
    {
        $box = $this->connectEmailBox();
        $unreadBefore = $this->messages->unreadSummary($this->supplierId);

        $this->importOne($this->received(['status' => 4, 'acceptance' => null]));

        $message = $this->stored($box['id'], '8800001');
        self::assertSame(4, $message['status']);
        self::assertNull($message['acknowledged_at']);
        self::assertSame($unreadBefore['unread'] + 1, $this->messages->unreadSummary($this->supplierId)['unread']);
    }

    public function testAnEmailBoxAndAnIsdsBoxBothAcceptImports(): void
    {
        $emailBox = $this->connectEmailBox();
        $isdsBox = $this->connectBox();
        $toIsds = Z::seal(Z::messageXml(['dm_id' => '8800002', 'sender' => $this->thirdParty, 'recipient' => $isdsBox['db_id']]));

        self::assertSame('created', $this->importOne($this->received())['outcome']);
        $result = $this->importOne($toIsds);

        self::assertSame('created', $result['outcome']);
        self::assertSame($isdsBox['id'], $result['databox_id']);
        self::assertNotNull($this->stored($emailBox['id'], '8800001'));
        self::assertNotNull($this->stored($isdsBox['id'], '8800002'));
    }

    // ---- delivery receipts ----

    public function testAReceiptCompletesAMessageKnownFromItsNoticeAndALaterNoticeChangesNothing(): void
    {
        $box = $this->connectEmailBox();
        $delivered = new \DateTimeImmutable('2026-10-20 10:00:00');
        $notice = [
            'dm_id' => '8800001', 'sender_box_id' => $this->thirdParty, 'sender_name' => 'Úřad', 'sender_type' => null,
            'recipient_box_id' => self::OWN_BOX, 'recipient_name' => 'Jana', 'annotation' => 'Z upozornění', 'delivery_time' => $delivered,
        ];
        $first = $this->messages->insertFromEmailNotice($box['id'], $notice, new \DateTimeImmutable('2026-10-20 10:05:00'));
        self::assertSame(4, $this->stored($box['id'], '8800001')['status']);

        $receipt = Z::seal(Z::receiptXml(['sender' => $this->thirdParty, 'recipient' => self::OWN_BOX]));
        $result = $this->importOne($receipt, 'dorucenka.zfo');

        self::assertSame('updated', $result['outcome'], $result['reason']);
        self::assertSame('receipt', $result['kind']);
        self::assertSame($first['id'], $result['message_id']);
        $message = $this->stored($box['id'], '8800001');
        self::assertSame(6, $message['status']);
        self::assertSame('2026-10-20 10:00:05', $message['acceptance_time']);
        self::assertSame('zfo', $this->sourceOf($message['id']));
        self::assertTrue($message['has_delivery_zfo']);
        self::assertTrue($message['has_delivery_info']);
        self::assertNull($message['acknowledged_at'], 'a message the user has not dealt with yet stays on the dashboard');
        $detail = $this->messageService->detail($this->supplierId, $message['id']);
        self::assertCount(2, $detail['receipt']['events']);
        self::assertStringContainsString('doručena přihlášením', $detail['receipt']['events'][1]['text']);

        // The notice arrives again (or late): the signed data stay.
        $again = $this->messages->insertFromEmailNotice($box['id'], ['annotation' => 'Jiné'] + $notice, new \DateTimeImmutable('2026-10-21 09:00:00'));
        self::assertFalse($again['is_new']);
        $message = $this->stored($box['id'], '8800001');
        self::assertSame(6, $message['status']);
        self::assertSame('Synthetic subject', $message['annotation']);
        self::assertSame('zfo', $this->sourceOf($message['id']));
    }

    public function testAReceiptForAnUnknownMessageCreatesTheEnvelope(): void
    {
        $box = $this->connectEmailBox();

        $result = $this->importOne(Z::seal(Z::receiptXml(['sender' => $this->thirdParty, 'recipient' => self::OWN_BOX, 'annotation' => 'Jen z doručenky'])));

        self::assertSame('created', $result['outcome']);
        self::assertStringContainsString('vznikla jen její obálka', $result['reason']);
        $message = $this->stored($box['id'], '8800001');
        self::assertSame('Jen z doručenky', $message['annotation']);
        self::assertSame(6, $message['status']);
        self::assertFalse($message['has_zfo']);
        self::assertTrue($message['has_delivery_zfo']);
        self::assertNotNull($message['acknowledged_at'], 'delivered history');
        $detail = $this->messageService->detail($this->supplierId, $message['id']);
        self::assertNull($detail['attachments']);
        self::assertCount(2, $detail['receipt']['events']);
    }

    public function testAnOlderFileNeverMovesTheStateBackwards(): void
    {
        $box = $this->connectEmailBox();
        $this->importOne($this->received(['status' => 7]));

        $result = $this->importOne($this->received(['status' => 6, 'annotation' => 'Older copy']));

        self::assertSame('updated', $result['outcome']);
        $message = $this->stored($box['id'], '8800001');
        self::assertSame(7, $message['status'], 'read stays read');
        self::assertSame('Older copy', $message['annotation'], 'the envelope is the signed one');
    }

    // ---- sent messages and tax filings ----

    private function sentFiling(string $xml, array $o = []): string
    {
        return Z::seal(Z::messageXml($o + [
            'dm_id' => '8800010', 'sender' => self::OWN_BOX, 'recipient' => $this->thirdParty, 'sender_name' => 'Jana Testovací',
            'recipient_name' => 'Finanční úřad pro Testovací kraj', 'annotation' => 'Přiznání k DPH',
            'delivery' => '2026-10-24T10:00:00.000+02:00', 'acceptance' => '2026-10-24T10:00:05.000+02:00',
            'files' => [['name' => 'dphdp3-2026-09.xml', 'mime' => 'application/xml', 'bytes' => $xml]],
        ]));
    }

    public function testASentMessageCarryingAnArchivedFilingIsLinkedAndFilesItLikeASendFromTheApplication(): void
    {
        $box = $this->connectEmailBox();
        $xml = self::epoXml();
        $submissionId = $this->archiveSubmission('dphdp3', $xml);

        $result = $this->importOne($this->sentFiling($xml));

        self::assertSame('created', $result['outcome'], $result['reason']);
        self::assertSame('sent', $result['direction']);
        self::assertSame(['submission_id' => $submissionId, 'status' => 'recorded', 'message' => null], $result['filing']);
        $row = $this->submissionRepo->findMeta($submissionId, $this->supplierId);
        self::assertSame('filed', $row['filing_status']);
        self::assertSame('data_box', $row['filing_channel']);
        self::assertSame('8800010', $row['filing_reference']);
        self::assertSame('2026-10-24 10:00:00', $row['filed_at'], 'the time ISDS recorded the delivery');
        $links = $this->messages->linksForSubmissions([$submissionId], $this->supplierId)[$submissionId];
        self::assertCount(1, $links);
        self::assertSame($result['message_id'], $links[0]['message_id']);

        // The sealed delivery receipt then confirms the filing, exactly as the receipt of an application send does.
        $receipt = Z::seal(Z::receiptXml([
            'dm_id' => '8800010', 'sender' => self::OWN_BOX, 'recipient' => $this->thirdParty,
            'delivery' => '2026-10-24T10:00:00.000+02:00', 'acceptance' => '2026-10-24T10:00:05.000+02:00',
        ]));
        $second = $this->importOne($receipt, 'dorucenka.zfo');
        self::assertSame('updated', $second['outcome']);
        $row = $this->submissionRepo->findMeta($submissionId, $this->supplierId);
        self::assertSame('confirmed', $row['filing_status']);
        self::assertSame('datova-zprava-8800010-dorucenka.zfo', $row['receipt_name']);
        self::assertSame($receipt, $this->submissionRepo->receipt($submissionId, $this->supplierId)['content']);
    }

    public function testTheReceiptCanComeFirst(): void
    {
        $this->connectEmailBox();
        $xml = self::epoXml();
        $submissionId = $this->archiveSubmission('dphdp3', $xml);
        $receipt = Z::seal(Z::receiptXml(['dm_id' => '8800010', 'sender' => self::OWN_BOX, 'recipient' => $this->thirdParty]));

        $first = $this->importOne($receipt);
        self::assertSame('generated', $this->submissionRepo->findMeta($submissionId, $this->supplierId)['filing_status'], 'a receipt carries no attachments to match');

        $this->importOne($this->sentFiling($xml));
        self::assertSame('confirmed', $this->submissionRepo->findMeta($submissionId, $this->supplierId)['filing_status']);
        self::assertSame('created', $first['outcome']);
    }

    public function testASentXmlThatMatchesNoArchivedFilingFilesNothing(): void
    {
        $this->connectEmailBox();
        $archived = $this->archiveSubmission('dphdp3', self::epoXml('451'));

        $result = $this->importOne($this->sentFiling(self::epoXml('999')));

        self::assertSame('created', $result['outcome']);
        self::assertSame('no_match', $result['filing']['status']);
        self::assertStringContainsString('neodpovídá žádnému archivovanému výkazu', $result['reason']);
        $row = $this->submissionRepo->findMeta($archived, $this->supplierId);
        self::assertSame('generated', $row['filing_status'], 'same form and period, but another checksum: never guessed');
        self::assertSame([], $this->messages->linksForSubmissions([$archived], $this->supplierId));
    }

    public function testAnAttachmentThatIsNoFilingHasNoFilingOutcome(): void
    {
        $this->connectEmailBox();
        $this->archiveSubmission('dphdp3', self::epoXml());

        $result = $this->importOne($this->sentFiling('<note>just text</note>'));

        self::assertNull($result['filing']);
    }

    public function testAFilingAlreadyFiledElsewhereKeepsItsFiling(): void
    {
        $this->connectEmailBox();
        $xml = self::epoXml();
        $submissionId = $this->archiveSubmission('dphdp3', $xml);
        $this->filing->markFiled($this->supplierId, $submissionId, '2026-10-20 09:00:00', 'epo', 'EPO-1', $this->userId);

        $result = $this->importOne($this->sentFiling($xml));

        self::assertSame('kept', $result['filing']['status']);
        $row = $this->submissionRepo->findMeta($submissionId, $this->supplierId);
        self::assertSame('epo', $row['filing_channel']);
        self::assertSame('2026-10-20 09:00:00', $row['filed_at']);
    }

    public function testAMessageISDSDidNotTakeFilesNothing(): void
    {
        $this->connectEmailBox();
        $xml = self::epoXml();
        $submissionId = $this->archiveSubmission('dphdp3', $xml);

        $result = $this->importOne($this->sentFiling($xml, ['status' => 3, 'delivery' => null, 'acceptance' => null]));

        self::assertSame('linked', $result['filing']['status']);
        self::assertSame('generated', $this->submissionRepo->findMeta($submissionId, $this->supplierId)['filing_status']);
    }

    public function testAReUploadIsADuplicateAndTheRepeatedUploadCompletesTheFilingArchivedMeanwhile(): void
    {
        $box = $this->connectEmailBox();
        $xml = self::epoXml();
        $file = $this->sentFiling($xml);

        $first = $this->importOne($file);
        $second = $this->importOne($file);

        self::assertSame('created', $first['outcome']);
        self::assertSame('no_match', $first['filing']['status'], 'nothing is archived yet');
        self::assertSame('duplicate', $second['outcome']);
        self::assertSame($first['message_id'], $second['message_id']);
        self::assertSame(1, $this->messages->list($box['id'], ['direction' => 'sent'], 50, 0)['total']);

        // The report is archived afterwards (e.g. regenerated identically): the same file now finds it.
        $submissionId = $this->archiveSubmission('dphdp3', $xml);
        $third = $this->importOne($file);
        self::assertSame('updated', $third['outcome']);
        self::assertSame('recorded', $third['filing']['status']);
        self::assertSame('filed', $this->submissionRepo->findMeta($submissionId, $this->supplierId)['filing_status']);
        self::assertSame('duplicate', $this->importOne($file)['outcome'], 'and once the filing is recorded there is nothing left to do');
    }

    // ---- directions ----

    public function testAMessageBetweenTwoOwnBoxesIsStoredInBoth(): void
    {
        $a = $this->connectEmailBox();
        $otherId = Z::boxId('tstbx2');
        $b = $this->connectEmailBox($otherId, 'PO');

        $result = $this->importOne(Z::seal(Z::messageXml(['sender' => self::OWN_BOX, 'recipient' => $otherId])));

        self::assertSame('created', $result['outcome']);
        self::assertNotNull($this->stored($b['id'], '8800001', 'received'));
        self::assertNotNull($this->stored($a['id'], '8800001', 'sent'));
        self::assertNull($this->stored($a['id'], '8800001', 'received'));
    }

    public function testAMessageOfNoConnectedBoxIsRejected(): void
    {
        $box = $this->connectEmailBox();

        $result = $this->importOne(Z::seal(Z::messageXml(['sender' => $this->thirdParty, 'recipient' => $this->foreign])));

        self::assertSame('rejected', $result['outcome']);
        self::assertStringContainsString('nepatří k žádné připojené schránce', $result['reason']);
        self::assertSame(0, $this->messages->list($box['id'], [], 50, 0)['total']);
    }

    public function testAMessageOfAnotherSuppliersBoxIsRejected(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has a single supplier.');
        }
        $this->connectEmailBox(self::OWN_BOX, 'PFO', $this->otherSupplierId);

        $result = $this->importOne($this->received());

        self::assertSame('rejected', $result['outcome'], 'the box belongs to another tenant');
    }

    // ---- refusals ----

    public function testTamperedBytesAreRejectedAndNothingIsStored(): void
    {
        $box = $this->connectEmailBox();
        $zfo = $this->received();
        $at = strpos($zfo, 'Synthetic subject');
        self::assertNotFalse($at);
        $zfo[$at] = 'X';

        $result = $this->importOne($zfo);

        self::assertSame('rejected', $result['outcome']);
        self::assertStringContainsString('Podpis souboru ZFO se nepodařilo ověřit', $result['reason']);
        self::assertNull($this->stored($box['id'], '8800001'));
    }

    public function testFilesThatAreNotAValidZfoAreRejected(): void
    {
        $box = $this->connectEmailBox();
        $cases = [
            'plain text' => ['not a zfo', 'není ZFO'],
            'empty' => ['', 'prázdný'],
            'a PDF' => ["%PDF-1.4\n%synthetic", 'není ZFO'],
            'a CMS with another content' => [Z::seal('<a>no data message</a>'), 'neobsahuje datovou zprávu ani doručenku'],
            'a CMS without the content' => [Z::seal(Z::messageXml(['sender' => $this->thirdParty, 'recipient' => self::OWN_BOX]), null, true), 'Podpis souboru ZFO'],
            'a CMS with a damaged tail' => [substr($this->received(), 0, 400), 'Podpis souboru ZFO'],
        ];
        foreach ($cases as $label => [$bytes, $expected]) {
            $result = $this->importOne($bytes);
            self::assertSame('rejected', $result['outcome'], $label);
            self::assertStringContainsString($expected, $result['reason'], $label);
        }
        self::assertSame(0, $this->messages->list($box['id'], [], 50, 0)['total']);
    }

    public function testABigFileAndAFileThatDidNotArriveAreRejectedWithoutStoppingTheOthers(): void
    {
        $this->connectEmailBox();

        $results = $this->zfoImport->import($this->supplierId, $this->userId, [
            ['name' => 'big.zfo', 'bytes' => str_repeat('a', DataboxZfoImportService::MAX_FILE_BYTES + 1)],
            ['name' => 'lost.zfo', 'bytes' => null, 'problem' => 'Soubor se nepodařilo nahrát.'],
            ['name' => "../evil/\x01name.zfo", 'bytes' => $this->received()],
        ]);

        self::assertSame(['rejected', 'rejected', 'created'], array_column($results, 'outcome'));
        self::assertStringContainsString('větší než 25 MB', $results[0]['reason']);
        self::assertSame('Soubor se nepodařilo nahrát.', $results[1]['reason']);
        self::assertSame('name.zfo', $results[2]['name'], 'no path and no control characters in the name shown');
    }

    public function testNothingToImportAndTooManyFilesAreRefused(): void
    {
        foreach ([[], array_fill(0, DataboxZfoImportService::MAX_FILES + 1, ['name' => 'a.zfo', 'bytes' => 'x'])] as $files) {
            try {
                $this->zfoImport->import($this->supplierId, $this->userId, $files);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame(400, $e->httpStatus);
            }
        }
    }

    // ---- precedence of sources ----

    public function testAMessageTheBoxSystemAlreadyConfirmedKeepsItsSource(): void
    {
        $box = $this->connectBox();
        $this->messages->upsertEnvelope($box['id'], 'received', [
            'dm_id' => '8800001', 'sender_box_id' => $this->thirdParty, 'recipient_box_id' => $box['db_id'], 'status' => 6,
            'delivery_time' => new \DateTimeImmutable('2026-10-20 10:00:00'),
        ], new \DateTimeImmutable('2026-10-20 12:00:00'));
        $zfo = Z::seal(Z::messageXml(['dm_id' => '8800001', 'sender' => $this->thirdParty, 'recipient' => $box['db_id']]));

        $result = $this->importOne($zfo);

        self::assertSame('updated', $result['outcome']);
        $message = $this->stored($box['id'], '8800001');
        self::assertSame('isds', $this->sourceOf($message['id']));
        self::assertTrue($message['has_zfo']);
        self::assertSame('Synthetic subject', $message['annotation']);
    }
}
