<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient;
use PHPUnit\Framework\TestCase;

final class WebklexImapMailboxClientTest extends TestCase
{
    /**
     * Regression: in Webklex getUid() is a magic method (only @method + __call), so
     * method_exists($msg, 'getUid') === false. The guard `method_exists(...) ? ... : null`
     * therefore stored uid = null for every message → postProcess returned at once and
     * "mark as read" (and add_flag/move) never ran.
     */
    public function testUidIsReadFromMagicGetUidAccessor(): void
    {
        $message = new MagicWebklexMessageStub([
            'getUid' => 42,
            'getMessageId' => '<mid@bank.cz>',
            'getFrom' => 'noreply@bank.cz',
            'getSubject' => 'Avízo o platbě',
            'getDate' => '2026-01-15 10:00:00',
            'getHTMLBody' => '',
            'getTextBody' => 'Přijatá platba 1000 CZK',
        ]);

        // Exactly the property that caused the bug: method_exists does not see the magic getUid().
        self::assertFalse(method_exists($message, 'getUid'));

        $notice = $this->toNotice($message, ['allow_forwarded' => false, 'forwarded_from' => '']);

        self::assertSame(42, $notice->uid);
        self::assertSame('<mid@bank.cz>', $notice->messageId);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function toNotice(object $message, array $settings): BankEmailNoticeMessage
    {
        $toNoticeMessage = new \ReflectionMethod(WebklexImapMailboxClient::class, 'toNoticeMessage');

        /** @var BankEmailNoticeMessage */
        return $toNoticeMessage->invoke(new WebklexImapMailboxClient(new EmailNoticeTextNormalizer()), $message, $settings);
    }

    public function testRawHeaderBlockAndBodyAreHandedOverUntouched(): void
    {
        $rawHeader = "From: a@b.cz\r\nSubject: x\r\n\r\n";
        $notice = $this->toNotice(new RawPartsWebklexMessageStub($rawHeader, "Body \r\n"), ['require_email_auth' => true]);

        self::assertSame($rawHeader, $notice->rawHeader);
        self::assertSame("Body \r\n", $notice->raw, 'the undecoded body DKIM hashes');
        self::assertSame('decoded text, not the raw body', $notice->text, 'parsing keeps using the decoded text');
    }

    public function testRawHeaderIsSkippedWhenAuthenticityIsNotRequired(): void
    {
        $message = new RawPartsWebklexMessageStub("From: a@b.cz\r\n\r\n", 'Body');

        self::assertSame('', $this->toNotice($message, [])->rawHeader);
        self::assertSame('', $this->toNotice($message, ['require_email_auth' => false])->rawHeader);
    }

    public function testMessageObjectWithoutRawAccessorsYieldsNoRawHeaderInsteadOfThrowing(): void
    {
        // getHeader() gives '' (no header object) and there is no real getRawBody().
        $message = new MagicWebklexMessageStub(['getUid' => 1, 'getTextBody' => 'x']);

        self::assertSame('', $this->toNotice($message, ['require_email_auth' => true])->rawHeader);
    }

    public function testAuthenticationResultsAreReadFromTheRawHeaderBlock(): void
    {
        $rawHeader = "Authentication-Results: mx.example.cz;\r\n\tdkim=pass header.d=airbank.cz\r\nFrom: a@b.cz\r\n\r\n";
        $notice = $this->toNotice(new RawPartsWebklexMessageStub($rawHeader, 'Body'), []);

        self::assertSame(['mx.example.cz; dkim=pass header.d=airbank.cz'], $notice->authResults);
    }
}

/**
 * Imitates Webklex\PHPIMAP\Message: the accessors (getUid, getFrom, …) exist only
 * through the magic __call, so method_exists() is false for them — exactly like in the library.
 */
final class MagicWebklexMessageStub
{
    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values) {}

    public function __call(string $method, array $arguments): mixed
    {
        return $this->values[$method] ?? '';
    }
}

/**
 * A Webklex message that exposes the raw header block and raw body the way the library does.
 */
final class RawPartsWebklexMessageStub
{
    public function __construct(private readonly string $rawHeader, private readonly string $rawBody) {}

    public function getHeader(): object
    {
        return (object) ['raw' => $this->rawHeader];
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function __call(string $method, array $arguments): mixed
    {
        return match ($method) {
            'getUid' => 5,
            'getTextBody' => 'decoded text, not the raw body',
            default => '',
        };
    }
}
