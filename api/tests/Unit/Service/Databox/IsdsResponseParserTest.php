<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Service\Databox\IsdsResponseParser;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use PHPUnit\Framework\TestCase;

final class IsdsResponseParserTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
    }

    public function testFixturesConformToTheOfficialSchemas(): void
    {
        $dbOperations = ['GetOwnerInfoFromLogin2Response', 'GetUserInfoFromLogin2Response', 'GetPasswordInfoResponse', 'FindDataBox2Response'];
        libxml_use_internal_errors(true);
        foreach (glob(DataboxFixtures::path('*.xml')) as $file) {
            $doc = new \DOMDocument();
            $doc->load($file);
            $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
            $operation = null;
            foreach ($body->childNodes as $n) {
                if ($n instanceof \DOMElement) {
                    $operation = $n;
                    break;
                }
            }
            if ($operation->localName === 'Fault') {
                continue;
            }
            $only = new \DOMDocument();
            $only->appendChild($only->importNode($operation, true));
            $xsd = in_array($operation->localName, $dbOperations, true) ? 'dbTypes.xsd' : 'dmBaseTypes.xsd';
            self::assertTrue($only->schemaValidate(dirname(__DIR__, 3) . '/Fixtures/Databox/xsd/' . $xsd), basename($file) . ' is not valid against ' . $xsd);
        }
        libxml_clear_errors();
    }

    public function testOwnerInfoOfABusinessBox(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('owner_info_pfo'), 'GetOwnerInfoFromLogin2Response');
        self::assertSame(['code' => '0000', 'message' => 'Provedeno úspěšně.'], IsdsResponseParser::status($r));
        self::assertSame([
            'db_id' => 'tpbxbx7', 'db_type' => 'PFO', 'ic' => '12345678', 'name' => 'Jan Testovací - IT služby', 'state' => 1,
        ], IsdsResponseParser::ownerInfo($r));
    }

    public function testOwnerInfoOfAPersonalBoxUsesThePersonName(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('owner_info_fo'), 'GetOwnerInfoFromLogin2Response');
        $owner = IsdsResponseParser::ownerInfo($r);
        self::assertSame('FO', $owner['db_type']);
        self::assertSame('Jan Testovací', $owner['name']);
        self::assertNull($owner['ic'], 'xsi:nil becomes null');
    }

    public function testUserInfoPrivileges(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('user_info_list_only'), 'GetUserInfoFromLogin2Response');
        self::assertSame(['user_type' => 'ENTRUSTED_USER', 'privileges' => 8, 'isds_id' => 'DS_fixture', 'name' => 'Jan Testovací'], IsdsResponseParser::userInfo($r));
    }

    public function testPasswordExpiryIsConvertedToTheApplicationZone(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('password_info'), 'GetPasswordInfoResponse');
        $expiry = IsdsResponseParser::passwordExpiry($r);
        self::assertSame('2027-01-15 10:30:00', $expiry->format('Y-m-d H:i:s'));
    }

    public function testReceivedListEnvelopes(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('list_received'), 'GetListOfReceivedMessagesResponse');
        $records = IsdsResponseParser::messageRecords($r);

        self::assertCount(3, $records);
        $tax = $records[0];
        self::assertSame('4101001', $tax['dm_id']);
        self::assertSame('7nyn2d9', $tax['sender_box_id']);
        self::assertSame('Finanční úřad pro hlavní město Prahu', $tax['sender_name']);
        self::assertSame(10, $tax['sender_type']);
        self::assertSame('Výzva k odstranění pochybností - kontrolní hlášení 03/2026', $tax['annotation']);
        self::assertSame(4, $tax['status']);
        self::assertSame('2026-10-27 09:15:30', $tax['delivery_time']->format('Y-m-d H:i:s'));
        self::assertNull($tax['acceptance_time'], 'not delivered yet -> nil');
        self::assertFalse($tax['personal_delivery']);
        self::assertTrue($tax['allow_subst_delivery']);
        self::assertSame('123456/26/2000-1', $tax['sender_ref_number']);
        self::assertNull($tax['to_hands']);

        self::assertSame(6, $records[1]['status']);
        self::assertSame('2026-10-20 14:30:00', $records[1]['acceptance_time']->format('Y-m-d H:i:s'));
        self::assertTrue($records[2]['personal_delivery']);
        self::assertSame(5, $records[2]['status']);
    }

    public function testEmptyListIsNotAnError(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('list_empty'), 'GetListOfReceivedMessagesResponse');
        self::assertSame([], IsdsResponseParser::messageRecords($r));
    }

    public function testDeliveryInfo(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('delivery_info'), 'GetDeliveryInfoResponse');
        $info = IsdsResponseParser::deliveryInfo($r);

        self::assertSame(6, $info['status']);
        self::assertSame('2026-10-24 10:00:05', $info['acceptance_time']->format('Y-m-d H:i:s'));
        self::assertCount(3, $info['events']);
        self::assertStringStartsWith('EV11:', $info['events'][2]['text']);
        self::assertStringContainsString('dmDelivery', $info['xml']);
        self::assertStringNotContainsString('dmQTimestamp', $info['xml'], 'the bulky time stamp is not kept in the readable copy');
        self::assertStringContainsString('EV5:', $info['xml']);
    }

    public function testSignatureIsDecoded(): void
    {
        $xml = DataboxFixtures::signedResponse('SignedMessageDownload', "binary\x00data");
        $r = IsdsResponseParser::operationElement($xml, 'SignedMessageDownloadResponse');
        self::assertSame("binary\x00data", IsdsResponseParser::signature($r));
    }

    public function testCreatedMessageId(): void
    {
        $r = IsdsResponseParser::operationElement(DataboxFixtures::xml('create_message_ok'), 'CreateMessageResponse');
        self::assertSame('4102002', IsdsResponseParser::createdMessageId($r));
    }

    public function testFoundBoxesAndTheNothingFoundInformationStatus(): void
    {
        $found = IsdsResponseParser::operationElement(DataboxFixtures::xml('find_databox_found'), 'FindDataBox2Response');
        $boxes = IsdsResponseParser::foundBoxes($found);
        self::assertSame('7nyn2d9', $boxes[0]['db_id']);
        self::assertSame('OVM', $boxes[0]['db_type']);

        $none = IsdsResponseParser::operationElement(DataboxFixtures::xml('find_databox_none'), 'FindDataBox2Response');
        self::assertSame([], IsdsResponseParser::foundBoxes($none));
        self::assertSame('0002', IsdsResponseParser::assertSuccess($none)['code'], '0002 is information, not a failure');
    }

    public function testSoapFaultBecomesAServerError(): void
    {
        try {
            IsdsResponseParser::operationElement(DataboxFixtures::xml('soap_fault'), 'GetListOfReceivedMessagesResponse');
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('server_error', $e->reason);
            self::assertStringContainsString('Internal processing error', $e->getMessage());
        }
    }

    public function testMissingOperationElementIsAnInvalidResponse(): void
    {
        $this->expectException(IsdsException::class);
        $this->expectExceptionMessage('chybí GetPasswordInfoResponse');
        IsdsResponseParser::operationElement(DataboxFixtures::xml('create_message_ok'), 'GetPasswordInfoResponse');
    }

    public function testDoctypeAndBrokenXmlAreRejected(): void
    {
        foreach (['<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><a>&e;</a>', '<not xml', ''] as $bad) {
            try {
                IsdsResponseParser::load($bad);
                self::fail('expected IsdsException for: ' . $bad);
            } catch (IsdsException $e) {
                self::assertSame('invalid_response', $e->reason);
            }
        }
    }

    public function testADoctypeHiddenInUtf16IsRejectedByTheParsedDocument(): void
    {
        // The byte search for "<!DOCTYPE" cannot see it between the NUL bytes of UTF-16: only the parsed document knows.
        $xml = DataboxFixtures::utf16('<!DOCTYPE a [<!ENTITY e "x">]><a>&e;</a>');
        self::assertFalse(stripos($xml, '<!DOCTYPE'), 'precondition: the raw bytes do not contain the marker');

        $this->expectException(IsdsException::class);
        $this->expectExceptionMessage('nepovolená DTD');
        IsdsResponseParser::load($xml);
    }

    public function testAUtf16BillionLaughsPayloadIsRefusedWithoutExpandingIt(): void
    {
        $before = memory_get_peak_usage();
        $bomb = DataboxFixtures::utf16BillionLaughs();

        foreach (['any xml' => static fn () => IsdsResponseParser::load($bomb), 'soap response' => static fn () => IsdsResponseParser::operationElement($bomb, 'AnyResponse')] as $what => $parse) {
            try {
                $parse();
                self::fail("the payload was parsed as {$what}");
            } catch (IsdsException $e) {
                self::assertSame('invalid_response', $e->reason, $what);
            }
        }
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before, 'nothing was expanded');
    }

    public function testTheLibxmlSizeLimitsAreLiftedForSoapResponsesOnly(): void
    {
        // Signed messages arrive as multi-megabyte base64 values, which is why a SOAP response is parsed without libxml's
        // limits (PARSEHUGE). Any other XML (an archived filing, a stored receipt) keeps them: here the nesting depth limit.
        $deep = '<?xml version="1.0" encoding="UTF-8"?>' . str_repeat('<a>', 300) . str_repeat('</a>', 300);

        self::assertInstanceOf(\DOMDocument::class, IsdsResponseParser::load($deep, true));
        try {
            IsdsResponseParser::load($deep);
            self::fail('any other XML keeps the size limits');
        } catch (IsdsException $e) {
            self::assertSame('invalid_response', $e->reason);
        }
    }

    public function testASoapResponseMustBePlainUtf8(): void
    {
        $plain = '<?xml version="1.0" encoding="UTF-8"?><a/>';
        self::assertInstanceOf(\DOMDocument::class, IsdsResponseParser::load($plain, true));

        foreach ([DataboxFixtures::utf16('<a/>'), "<?xml version=\"1.0\" encoding=\"UTF-7\"?><a/>", "<a>\0</a>"] as $odd) {
            try {
                IsdsResponseParser::load($odd, true);
                self::fail('an encoding that hides bytes from the DOCTYPE check was accepted');
            } catch (IsdsException $e) {
                self::assertStringContainsString('kódování', $e->getMessage());
            }
        }
    }

    public function testStatusCodesMapToReasons(): void
    {
        $cases = [
            'status_1222' => ['SignedMessageDownloadResponse', 'not_delivered', '1222'],
            'status_1004' => ['GetListOfReceivedMessagesResponse', 'forbidden', '1004'],
            'status_9999' => ['GetListOfReceivedMessagesResponse', 'isds_error', '9999'],
        ];
        foreach ($cases as $fixture => [$element, $reason, $code]) {
            try {
                IsdsResponseParser::assertSuccess(IsdsResponseParser::operationElement(DataboxFixtures::xml($fixture), $element));
                self::fail("{$fixture} should fail");
            } catch (IsdsException $e) {
                self::assertSame($reason, $e->reason, $fixture);
                self::assertSame($code, $e->isdsCode, $fixture);
            }
        }
    }

    public function testUnknownStatusKeepsTheCodeAndTheIsdsText(): void
    {
        $e = IsdsException::fromStatus('9999', "Neznámá   chyba\nserveru.");
        self::assertStringContainsString('kód 9999', $e->getMessage());
        self::assertStringContainsString('Neznámá chyba serveru.', $e->getMessage());
    }
}
