<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Unified archiving + XSD validation pipeline for EPO XML.
 *
 * Called from the DphPriznani/KontrolniHlaseni/SouhrnneHlaseni/IncomeTax/Oss action `download()`
 * **before** the XML response is sent. Returns the id of the archived row (for the UI link).
 */
final class TaxSubmissionArchiver
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly XmlSchemaValidator $validator,
    ) {}

    /**
     * Archives the XML and runs the XSD validation (when a schema exists).
     *
     * The filing form (B/O/N/E for DPH + KH, R for SH, B otherwise) is the `form` of the summary.
     *
     * @param array<string,mixed> $summary
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        ?int $generatedBy,
    ): array {
        // XSD validation
        $validation = $this->validator->validate($xml, $formCode);

        $id = $this->repo->archive(
            $supplierId,
            $formCode,
            $year,
            $month,
            $quarter,
            $xml,
            $summary,
            $validation['status'],
            $validation['errors'],
            $generatedBy,
            strtoupper((string) ($summary['form'] ?? 'B')),
        );

        return [
            'submission_id'     => $id,
            'validation_status' => $validation['status'],
            'validation_errors' => $validation['errors'],
        ];
    }
}
