<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use MyInvoice\Tests\Support\Imap\FakeImapServer;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real Webklex client against a loopback IMAP server (no real mail server involved)
 * to prove the raw message that reaches DKIM verification is byte-identical to what was sent.
 */
final class WebklexImapRoundTripTest extends TestCase
{
    private function settings(FakeImapServer $server, bool $requireAuth = true): array
    {
        return [
            'host' => '127.0.0.1', 'port' => $server->port, 'encryption' => 'none', 'validate_cert' => false,
            'username' => 'user', 'password' => 'secret', 'folder' => 'INBOX', 'require_email_auth' => $requireAuth,
        ];
    }

    private function client(): WebklexImapMailboxClient
    {
        return new WebklexImapMailboxClient(new EmailNoticeTextNormalizer());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function awkwardMessages(): array
    {
        $headers = "Received: from a (a [192.0.2.1])\r\n\tby b with ESMTP id X\r\n"
            . "From: Air Bank<info@airbank.cz>\r\nTo: owner@example.cz\r\nSubject: =?UTF-8?B?WnbDvcWhZW7DrQ==?=\r\n"
            . "Message-ID: <rt@airbank.cz>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";
        return [
            'plain utf-8 body' => [$headers . "\r\nDobrý den,\r\nzůstatek se zvýšil.\r\n"],
            'trailing blanks and blank tail' => [$headers . "\r\nline with trailing blanks   \t\r\n\r\n\r\n"],
            'no final CRLF' => [$headers . "\r\nbody without a line break at the end"],
            'empty body' => [$headers . "\r\n"],
            'lone LF and lone CR inside the body' => [$headers . "\r\nlf only\nline\rcr only\r\nend\r\n"],
            'binary-ish bytes' => [$headers . "\r\n\x00\x01\xFF\xFE high bytes \xC3\x28 invalid utf-8\r\n"],
            'looks like IMAP protocol text' => [$headers . "\r\n* 1 FETCH (UID 99)\r\nA001 OK done\r\n)\r\n{5}\r\n"],
            'multipart with quoted-printable and base64' => [
                str_replace('Content-Type: text/plain; charset=UTF-8', 'Content-Type: multipart/alternative; boundary="b1"', $headers)
                . "\r\n--b1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nz=C5=AFstatek =\r\nse zv=C3=BD=C5=A1il\r\n"
                . "--b1\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode('<p>zůstatek</p>'), 76, "\r\n")
                . "--b1--\r\n",
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardMessages')]
    public function testRawMessageRoundTripsByteForByte(string $raw): void
    {
        $server = new FakeImapServer([7 => $raw]);
        $messages = $this->client()->latest($this->settings($server), 10);

        self::assertCount(1, $messages);
        self::assertSame(7, $messages[0]->uid);
        self::assertSame($raw, $messages[0]->rawHeader . $messages[0]->raw, 'DKIM hashes the bytes on the wire, so they must survive untouched');
    }

    public function testRawHeaderIsOnlyCapturedWhenAuthenticityIsRequired(): void
    {
        $server = new FakeImapServer([1 => "From: a@airbank.cz\r\nSubject: x\r\n\r\nbody\r\n"]);
        $messages = $this->client()->latest($this->settings($server, requireAuth: false), 10);
        self::assertSame('', $messages[0]->rawHeader);
    }

    public function testSignedMessageStillVerifiesAfterTheImapRoundTrip(): void
    {
        $key = DkimTestSigner::rsaKey(2048);
        $signed = DkimTestSigner::sign(DkimTestSigner::sampleMessage(messageId: '<rt@airbank.cz>'), ['key' => $key['private'], 'canon' => 'relaxed/relaxed']);

        $server = new FakeImapServer([3 => $signed]);
        $message = $this->client()->latest($this->settings($server), 10)[0];
        self::assertSame([], $message->authResults, 'this server (like Seznam) adds no Authentication-Results');

        $verifier = new EmailAuthenticationVerifier(new DkimVerifier(
            new ArrayDkimKeyResolver(['ab._domainkey.airbank.cz' => [$key['txt']]]),
            new FixedClock('2026-11-04 10:00:00 UTC'),
        ));
        $auth = $verifier->verify($message->authResults, 'airbank.cz', null, $message->rawHeader, $message->raw);
        self::assertTrue($auth->passed(), $auth->detail);
        self::assertSame('dkim_local_pass:airbank.cz', $auth->detail);

        // The same bytes with one changed in the body no longer verify.
        $tampered = $verifier->verify([], 'airbank.cz', null, $message->rawHeader, str_replace('1,00 CZK', '9,00 CZK', $message->raw));
        self::assertSame('dkim_body_hash_mismatch', $tampered->detail);
    }
}
