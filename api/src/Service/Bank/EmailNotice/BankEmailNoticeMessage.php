<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class BankEmailNoticeMessage
{
    /**
     * @param string $raw Raw (undecoded) MIME body as fetched from the mailbox.
     * @param list<string> $authResults Authentication-Results header values top to bottom
     *                                   (newest hop = receiving server = index 0).
     * @param bool $allowForwarded The mailbox receives forwarded (FW) notices: lets the bank be
     *                             detected from the body when `From` does not match (opt-in per account).
     * @param string $forwardedFrom Optional forwarder whitelist (address or domain);
     *                              empty = any. Applies to the forwarded branch only.
     * @param string $rawHeader The header block byte for byte as received (with `$raw` it makes up the
     *                          complete RFC 5322 message that DKIM hashes). Empty when the mailbox client
     *                          did not capture it, which it only does when authenticity is required.
     */
    public function __construct(
        public readonly ?int $uid,
        public readonly ?string $messageId,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $sender,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $raw,
        public readonly array $authResults = [],
        public readonly bool $allowForwarded = false,
        public readonly string $forwardedFrom = '',
        public readonly string $rawHeader = '',
    ) {}

    public function fallbackHash(): string
    {
        $date = $this->date?->format('c') ?? '';
        $base = strtolower($this->sender) . "\n" . $this->subject . "\n" . $date . "\n" . $this->text;
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($base)) ?? $base);
    }
}
