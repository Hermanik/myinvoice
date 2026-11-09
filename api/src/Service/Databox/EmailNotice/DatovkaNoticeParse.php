<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

/**
 * Outcome of reading one e-mail: a notice, an e-mail to leave alone, or a notice that could not be read completely.
 */
final readonly class DatovkaNoticeParse
{
    public const NOTICE = 'notice';
    public const IGNORED = 'ignored';
    public const FAILED = 'parse_failed';

    private function __construct(
        public string $outcome,
        public string $detail,
        public ?DatovkaNotice $notice = null,
    ) {}

    public static function notice(DatovkaNotice $notice): self
    {
        return new self(self::NOTICE, 'notice', $notice);
    }

    /** @param string $reason stable code, never part of the e-mail */
    public static function ignored(string $reason): self
    {
        return new self(self::IGNORED, $reason);
    }

    /** @param string $reason stable code, never part of the e-mail */
    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }
}
