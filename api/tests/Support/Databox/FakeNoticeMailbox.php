<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;

/** An IMAP mailbox that hands out the messages it was given and remembers how it was read. */
final class FakeNoticeMailbox implements ImapMailboxClientInterface
{
    /** @var list<BankEmailNoticeMessage> */
    public array $messages = [];
    /** @var list<array{settings:array<string,mixed>, limit:int}> */
    public array $reads = [];
    public int $postProcessed = 0;
    public ?\Throwable $failWith = null;

    /** @param list<string> $raws complete raw messages, newest first like a real mailbox lists them */
    public function fill(array $raws): void
    {
        $this->messages = array_map(static fn (string $raw, int $i): BankEmailNoticeMessage => DatovkaNoticeFixtures::message($raw, $i + 1), $raws, array_keys($raws));
    }

    public function latest(array $settings, int $limit): array
    {
        $this->reads[] = ['settings' => $settings, 'limit' => $limit];
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        return $this->messages;
    }

    public function test(array $settings): array
    {
        return ['ok' => true, 'message' => 'ok'];
    }

    public function postProcess(array $settings, BankEmailNoticeMessage $message, string $kind): ?string
    {
        $this->postProcessed++;
        return null;
    }
}
