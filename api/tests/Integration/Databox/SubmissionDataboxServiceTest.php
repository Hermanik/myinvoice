<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\SubmissionDataboxService;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * "Odeslat datovou schránkou" on an archived EPO XML: recipient from the FÚ codebook, the XML as the attachment,
 * explicit confirmation every time, delivery info kept on the submission.
 */
final class SubmissionDataboxServiceTest extends DataboxIntegrationTestCase
{
    private function sendingBox(): array
    {
        return $this->connectBox('user_info_list_only', 'user_info_sender');
    }

    private function scriptSend(string $dmId = '4102002'): void
    {
        $this->isds->queue('CreateMessage', str_replace('4102002', $dmId, DataboxFixtures::xml('create_message_ok')));
        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', DataboxFixtures::zfo($dmId, [['name' => 'x.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']])));
    }

    public function testPrefillTakesTheRecipientFromTheOfficeNamedInTheXml(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml('461'));

        $prefill = $this->submissions->prefill($this->supplierId, $id);

        self::assertSame(['c_ufo' => '461', 'name' => 'Finanční úřad pro Jihomoravský kraj', 'box_id' => 'qdhny4c', 'source' => 'xml'], $prefill['recipient']);
        self::assertSame('dphdp3-2026-09.xml', $prefill['file_name']);
        self::assertSame('Přiznání k DPH', $prefill['submission']['form_name']);
        self::assertSame('09/2026', $prefill['submission']['period_label']);
        self::assertStringStartsWith('Přiznání k DPH za 09/2026', $prefill['annotation']);
        self::assertSame($box['id'], $prefill['recommended_box_id']);
        self::assertNull($prefill['blocked_reason']);
        self::assertSame([], $prefill['previous_sends']);
        self::assertSame([], $prefill['warnings']);
    }

    public function testWithoutACUfoInTheXmlTheSuppliersOfficeIsTheFallback(): void
    {
        $this->sendingBox();
        $this->db->pdo()->prepare('UPDATE supplier SET financial_office_code = ? WHERE id = ?')->execute(['13', $this->supplierId]);
        try {
            $id = $this->archiveSubmission('dphkh1', '<Pisemnost><DPHKH1/></Pisemnost>');
            $recipient = $this->submissions->prefill($this->supplierId, $id)['recipient'];
            self::assertSame('7cs8cge', $recipient['box_id']);
            self::assertSame('supplier', $recipient['source']);
        } finally {
            $this->db->pdo()->prepare('UPDATE supplier SET financial_office_code = NULL WHERE id = ? AND financial_office_code = ?')->execute([$this->supplierId, '13']);
        }
    }

    public function testAnUnknownOfficeMeansTheRecipientMustBeTypedIn(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml('999'));

        $prefill = $this->submissions->prefill($this->supplierId, $id);
        self::assertNull($prefill['recipient']['box_id']);
        self::assertSame('none', $prefill['recipient']['source']);
        self::assertContains('no_office', $prefill['warnings']);

        try {
            $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('no_recipient', $e->errorCode);
        }

        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id'], ['recipient_box_id' => '7NYN2D9']);
        self::assertSame('7nyn2d9', $prepared['summary']['recipient']['box_id']);
        self::assertContains('no_office', $prepared['summary']['warnings']);
    }

    public function testAnXmlThatFailedTheXsdIsNeverSent(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml(), 'failed');

        self::assertSame('xsd_failed', $this->submissions->prefill($this->supplierId, $id)['blocked_reason']);
        $this->forgetOperations();
        try {
            $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('xsd_failed', $e->errorCode);
            self::assertStringContainsString('neúčinné', $e->getMessage());
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testAnUnvalidatedXmlCanBePreparedButOnlyGoesOutWithItsOwnConfirmation(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml(), 'skipped');
        self::assertContains('xsd_skipped', $this->submissions->prefill($this->supplierId, $id)['warnings']);

        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        self::assertContains('xsd_skipped', $prepared['summary']['warnings']);
        self::assertTrue($prepared['summary']['requires_unvalidated_confirmation']);

        // The ordinary confirmation (even with the re-send one) is not enough.
        $this->forgetOperations();
        foreach ([[false], [true]] as [$resend]) {
            try {
                $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, $resend);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame('unvalidated_confirmation_required', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
                self::assertStringContainsString('§ 101a', $e->getMessage());
            }
        }
        self::assertSame([], $this->isds->requests, 'nothing was sent');

        // The refusal did not burn the payload: the same token works with the separate confirmation.
        $this->scriptSend();
        $result = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, false, null, true);
        self::assertSame('4102002', $result['dm_id']);
    }

    public function testAValidatedXmlNeedsNoExtraConfirmation(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml(), 'passed');
        self::assertNotContains('xsd_skipped', $this->submissions->prefill($this->supplierId, $id)['warnings']);
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        self::assertFalse($prepared['summary']['requires_unvalidated_confirmation']);
        $this->scriptSend();
        self::assertSame('4102002', $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true)['dm_id']);
    }

    public function testAnArchivedXmlThatNoLongerMatchesItsChecksumIsNeverSent(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->db->pdo()->prepare('UPDATE tax_submissions SET xml_content = REPLACE(xml_content, ?, ?) WHERE id = ?')->execute(['rok="2026"', 'rok="2025"', $id]);

        self::assertSame('integrity_failed', $this->submissions->prefill($this->supplierId, $id)['blocked_reason']);
        $this->forgetOperations();
        try {
            $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('integrity_failed', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testAReplacedAttachmentWithAMatchingSummaryStillDoesNotPassForTheArchivedFiling(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);

        // Both the staged bytes and the checksum shown in the summary are replaced; only the filing's own hash is left.
        $other = self::epoXml('999');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE databox_outbox_files SET content = ?, sha256 = ? WHERE token = ?')->execute([$other, hash('sha256', $other), $prepared['token']]);
        $pdo->prepare("UPDATE databox_outbox SET summary_json = JSON_SET(summary_json, '$.files[0].sha256', ?) WHERE token = ?")->execute([hash('sha256', $other), $prepared['token']]);

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('integrity_failed', $e->errorCode);
        }
        self::assertSame(0, $this->isds->count('CreateMessage'));
    }

    public function testAStagedPayloadThatChangedSincePrepareIsNeverSent(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);

        // Someone edits the staged attachment between "prepare" and the confirmation.
        $this->db->pdo()->prepare('UPDATE databox_outbox_files SET content = REPLACE(content, ?, ?) WHERE token = ?')->execute(['rok="2026"', 'rok="2025"', $prepared['token']]);

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('integrity_failed', $e->errorCode);
        }
        self::assertSame(0, $this->isds->count('CreateMessage'), 'what was shown is not what would have been sent');
    }

    public function testSendingTheFilingStoresTheMessageAndLinksItToTheSubmission(): void
    {
        $box = $this->sendingBox();
        $xml = self::epoXml('451');
        $id = $this->archiveSubmission('dphdp3', $xml);

        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        $summary = $prepared['summary'];
        self::assertSame('7nyn2d9', $summary['recipient']['box_id']);
        self::assertTrue($summary['recipient']['is_tax_office']);
        self::assertSame(['dphdp3-2026-09.xml'], array_column($summary['files'], 'name'));
        self::assertSame('application/xml', $summary['files'][0]['mime']);
        self::assertSame(hash('sha256', $xml), $summary['files'][0]['sha256'], 'the archived XML is sent byte for byte');
        self::assertSame('dphdp3', $summary['form']['form_code']);
        self::assertSame([], $summary['previous_sends']);

        $this->forgetOperations();
        $this->scriptSend();
        $result = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);

        self::assertSame($id, $result['submission_id']);
        self::assertStringContainsString(base64_encode($xml), $this->isds->bodyOf('CreateMessage'));
        $links = $this->submissions->linksFor([$id], $this->supplierId)[$id];
        self::assertCount(1, $links);
        self::assertSame('4102002', $links[0]['dm_id']);
        self::assertSame(6, $links[0]['status']);
        self::assertTrue($links[0]['has_delivery_info']);
        self::assertTrue($links[0]['has_delivery_zfo']);
        self::assertSame('2026-10-24 10:00:05', $links[0]['acceptance_time']);
    }

    public function testAFilingThatWasAlreadySentNeedsAnExtraConfirmationToGoOutAgain(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $first = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        $this->scriptSend();
        $this->sender->send($this->supplierId, $this->userId, $first['token'], true);

        $again = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        self::assertTrue($again['summary']['requires_resend_confirmation']);
        self::assertSame('4102002', $again['summary']['previous_sends'][0]['dm_id']);
        self::assertCount(1, $this->submissions->prefill($this->supplierId, $id)['previous_sends']);

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $again['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('resend_confirmation_required', $e->errorCode);
        }
        self::assertSame([], $this->isds->requests);

        // The refusal did not burn the staged payload: the same token works with the extra confirmation.
        $this->scriptSend('4102003');
        $result = $this->sender->send($this->supplierId, $this->userId, $again['token'], true, true);
        self::assertSame('4102003', $result['dm_id']);
        self::assertCount(2, $this->submissions->linksFor([$id], $this->supplierId)[$id]);
    }

    public function testTheResendGuardHoldsWhenTheFilingWasSentFromAnotherTab(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        // Two dialogs are opened before either one sends: neither staged summary knows about a previous send.
        $tabA = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        $tabB = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        self::assertFalse($tabB['summary']['requires_resend_confirmation']);

        $this->scriptSend();
        $this->sender->send($this->supplierId, $this->userId, $tabA['token'], true);

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $tabB['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('resend_confirmation_required', $e->errorCode);
        }
        self::assertSame(0, $this->isds->count('CreateMessage'));
    }

    public function testAPersonalBoxIsFlaggedAndTheBusinessBoxIsRecommended(): void
    {
        $fo = $this->connectBox('user_info_list_only', 'user_info_sender', 'owner_info_fo');
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        self::assertSame($fo['id'], $this->submissions->prefill($this->supplierId, $id)['recommended_box_id'], 'the only box there is');

        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $fo['id']);
        self::assertContains('personal_box', $prepared['summary']['warnings'], 'a business filing belongs to the business box');

        $pfo = $this->connectBox('user_info_list_only', 'user_info_sender', 'owner_info_pfo');
        self::assertSame($pfo['id'], $this->submissions->prefill($this->supplierId, $id)['recommended_box_id'], 'the business box is recommended over the personal one');
        self::assertNotContains('personal_box', $this->submissions->prepare($this->supplierId, $this->userId, $id, $pfo['id'])['summary']['warnings']);
    }

    public function testSubmissionsOfAnotherSupplierAreInvisible(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has only one supplier.');
        }
        $box = $this->sendingBox();
        $foreign = $this->archiveSubmission('dphdp3', self::epoXml(), 'passed', 2026, 9, null, $this->otherSupplierId);

        foreach ([
            fn () => $this->submissions->prefill($this->supplierId, $foreign),
            fn () => $this->submissions->prepare($this->supplierId, $this->userId, $foreign, $box['id']),
        ] as $call) {
            try {
                $call();
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        self::assertSame([], $this->submissions->linksFor([$foreign], $this->supplierId));
    }

    public function testNamingHelpers(): void
    {
        $base = ['form_code' => 'DPHKH1', 'period_year' => 2026, 'period_month' => null, 'period_quarter' => 2];
        self::assertSame('dphkh1-2026-Q2.xml', SubmissionDataboxService::fileName($base));
        self::assertSame('2. čtvrtletí 2026', SubmissionDataboxService::periodLabel($base));
        self::assertSame('dpfdp5-2025.xml', SubmissionDataboxService::fileName(['form_code' => 'dpfdp5', 'period_year' => 2025, 'period_month' => null, 'period_quarter' => null]));
        self::assertSame('2025', SubmissionDataboxService::periodLabel(['period_year' => 2025, 'period_month' => null, 'period_quarter' => null]));
        self::assertSame('Souhrnné hlášení', SubmissionDataboxService::formName('dphshv'));
        self::assertSame('OSSEI1', SubmissionDataboxService::formName('ossei1'));
    }

    public function testOfficeCodeIsReadSafelyFromTheXml(): void
    {
        self::assertSame('451', SubmissionDataboxService::officeCodeFromXml(self::epoXml('451')));
        self::assertNull(SubmissionDataboxService::officeCodeFromXml('<Pisemnost><VetaP/></Pisemnost>'));
        self::assertNull(SubmissionDataboxService::officeCodeFromXml('not xml'));
        self::assertNull(SubmissionDataboxService::officeCodeFromXml('<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a c_ufo="&x;"/>'), 'no DTD is ever processed');
    }
}
