<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\AuthMethod;
use MyInvoice\Service\Bank\EmailNotice\AuthVerdict;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthResult;

/**
 * Decides whether a notification e-mail really comes from the data box system. Not configurable: an e-mail is trusted
 * only when
 *  - it is sent from notifikace@ one of the two domains the system uses (the older one is kept for notices from before
 *    the move to datovka.gov.cz), and
 *  - its own DKIM signature verifies, was made by that same domain and aligns with the From domain.
 *
 * Authentication-Results headers are deliberately not consulted: the mailbox provider may add none (Seznam), and a
 * header written by the sender proves nothing.
 */
final class DatovkaNoticeAuthenticator
{
    public const DOMAINS = ['datovka.gov.cz', 'mojedatovaschranka.cz'];
    private const LOCAL_PART = 'notifikace';

    public function __construct(private readonly EmailAuthenticationVerifier $verifier) {}

    /**
     * @param string $sender the From header as the mailbox client decoded it
     * @param string $rawHeader header block exactly as received
     * @param string $rawBody undecoded body exactly as received
     */
    public function verify(string $sender, string $rawHeader, string $rawBody): EmailAuthResult
    {
        $fail = static fn (string $detail): EmailAuthResult => new EmailAuthResult(AuthVerdict::Fail, AuthMethod::DkimLocal, $detail);

        $address = self::address($sender);
        if ($address === null || $address[0] !== self::LOCAL_PART || !in_array($address[1], self::DOMAINS, true)) {
            return $fail('sender_not_allowed');
        }

        // No Authentication-Results and no trusted server id: the message has to prove itself with its own signature.
        $result = $this->verifier->verify([], $address[1], null, $rawHeader, $rawBody);
        if (!$result->passed()) {
            return $result;
        }
        // The DKIM check accepts a signature of a parent or child of the From domain; here only the notifier's own domains count.
        $signedBy = substr($result->detail, strlen('dkim_local_pass:'));
        return in_array($signedBy, self::DOMAINS, true) ? $result : $fail('dkim_domain_not_allowed');
    }

    /** @return array{0:string, 1:string}|null local part and domain (lower-case) of a From value ("Name" <a@b.cz> or a@b.cz), null when it has no single address */
    private static function address(string $sender): ?array
    {
        $sender = trim($sender);
        if (preg_match('/<([^<>\s]+)>\s*$/', $sender, $m) === 1) {
            $sender = $m[1];
        }
        return preg_match('/^([^\s<>",;@]+)@([^\s<>",;@]+)$/', strtolower($sender), $m) === 1 ? [$m[1], $m[2]] : null;
    }
}
