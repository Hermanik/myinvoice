<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

final readonly class ExportPeriod
{
    public function __construct(
        public string $type,
        public int $year,
        public ?int $month,
        public ?int $quarter,
        public string $dateFrom,
        public string $dateToExclusive,
        public string $label,
    ) {}

    public function isQuarterly(): bool
    {
        return $this->type === 'quarterly';
    }

    public function isYearly(): bool
    {
        return $this->type === 'yearly';
    }

    /** Last day of the period, inclusive (`Y-m-d`). */
    public function dateToInclusive(): string
    {
        return (new \DateTimeImmutable($this->dateToExclusive))->modify('-1 day')->format('Y-m-d');
    }
}
