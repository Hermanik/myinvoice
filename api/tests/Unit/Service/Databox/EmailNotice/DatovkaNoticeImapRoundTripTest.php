<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeAuthenticator;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParse;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParser;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use MyInvoice\Tests\Support\Imap\FakeImapServer;
use PHPUnit\Framework\TestCase;

/**
 * The real Webklex client against a loopback IMAP server: a notification e-mail of the shape the data box system sends
 * (S/MIME multipart/signed, quoted-printable text/html only) must come out of the mailbox byte for byte, so that the MIME
 * walk and the DKIM check work on what a real mailbox hands over.
 */
final class DatovkaNoticeImapRoundTripTest extends TestCase
{
    public function testTheSignedNoticeSurvivesTheMailboxAndIsReadAndAuthenticated(): void
    {
        $key = DkimTestSigner::rsaKey(2048);
        $raw = F::signed(F::raw(['message_id' => '<rt-1@example.invalid>']), $key);
        $server = new FakeImapServer([5 => $raw]);

        $messages = (new WebklexImapMailboxClient(new EmailNoticeTextNormalizer()))->latest([
            'host' => '127.0.0.1', 'port' => $server->port, 'encryption' => 'none', 'validate_cert' => false,
            'username' => 'user', 'password' => 'secret', 'folder' => 'INBOX', 'require_email_auth' => true,
        ], 10);

        self::assertCount(1, $messages);
        $message = $messages[0];
        self::assertSame($raw, $message->rawHeader . $message->raw, 'the bytes DKIM hashes arrive untouched');
        self::assertSame('Nová datová zpráva pro: Jana Testovací - podnikající fyzická osoba', $message->subject);
        self::assertStringContainsString('notifikace@datovka.gov.cz', $message->sender);

        $parse = (new DatovkaNoticeParser())->parse($message->subject, $message->rawHeader, $message->raw, $message->date);
        self::assertSame(DatovkaNoticeParse::NOTICE, $parse->outcome, $parse->detail);
        self::assertSame(F::DM_ID, $parse->notice?->dmId);
        self::assertSame('Výzva k podání kontrolního hlášení', $parse->notice?->annotation);

        $auth = new DatovkaNoticeAuthenticator(new EmailAuthenticationVerifier(new DkimVerifier(
            new ArrayDkimKeyResolver(['20230317._domainkey.datovka.gov.cz' => [$key['txt']]]),
            new FixedClock('2026-11-09 10:00:00 UTC'),
        )));
        $result = $auth->verify($message->sender, $message->rawHeader, $message->raw);
        self::assertTrue($result->passed(), $result->detail);
        self::assertSame('dkim_local_pass:datovka.gov.cz', $result->detail);
    }
}
