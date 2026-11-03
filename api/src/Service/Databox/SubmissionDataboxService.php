<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * Sending an archived EPO XML filing (VAT return, control statement, summary statement, income tax return) to the tax
 * office's data box.
 *
 * Legal basis (all verified against the official sources, see the report of the feature):
 *  - A filing may be made electronically by a data message "with a verified identity of the submitter by the means with
 *    which one logs in to his data box" - sending it from the taxpayer's own box needs no electronic signature
 *    (tax code 280/2009 Sb. § 71 odst. 1 písm. b; guidance of the Financni sprava
 *    https://financnisprava.gov.cz/assets/cs/prilohy/d-sprava-dani-a-poplatku/Priklady-k-elektronickemu-podani-par-72_4-DR.pdf
 *    and https://podpora.mojedane.gov.cz/assets/cs/prilohy/podani_pomoci_ISDS.pdf).
 *  - Format: the XML in the structure of the EPO XSD, as the (first, "main") attachment; GFR instruction GFŘ-D-50
 *    (https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Pokyn_GFR_D_50.pdf). When a message has several
 *    files, it must be clear which one is the filing - the XML is always the only / the first one here.
 *  - A filing not made in the prescribed format / structure is ineffective (VAT act 235/2004 Sb. § 101a odst. 4), hence
 *    an XML that failed the XSD validation is never sent.
 *  - Recipient: the data box of the tax office that the XML itself names in its `c_ufo` attribute
 *    ({@see TaxOfficeCodebook}).
 *  - Proof of delivery is the receipt kept in the sender's box (stored by {@see DataboxReceiptService}).
 */
final class SubmissionDataboxService
{
    private const FORM_NAMES = [
        'dphdp3' => 'Přiznání k DPH',
        'dphkh1' => 'Kontrolní hlášení',
        'dphshv' => 'Souhrnné hlášení',
        'dpfdp5' => 'Přiznání k dani z příjmů FO',
        'dppdp9' => 'Přiznání k dani z příjmů PO',
    ];

    private const BLOCKED_MESSAGES = [
        'xsd_failed' => 'XML výkazu neprošlo validací proti schématu EPO. Takové podání by bylo neúčinné (§ 101a odst. 4 zákona o DPH), proto se neodešle. Opravte údaje a výkaz vygenerujte znovu.',
        'integrity_failed' => 'Archivované XML neodpovídá uloženému kontrolnímu součtu (SHA-256), takže ho nelze odeslat jako původní výkaz. Výkaz vygenerujte znovu.',
    ];

    public function __construct(
        private readonly TaxSubmissionRepository $submissions,
        private readonly DataboxMessageRepository $messages,
        private readonly DataboxService $service,
        private readonly DataboxSendService $sender,
        private readonly Connection $db,
    ) {}

    /**
     * Everything the "Odeslat datovou schránkou" dialog needs.
     *
     * @return array<string,mixed>
     * @throws DataboxException
     */
    public function prefill(int $supplierId, int $submissionId): array
    {
        $submission = $this->requireSubmission($supplierId, $submissionId);
        $supplier = $this->supplierRow($supplierId);
        $office = $this->officeFor($submission, $supplier);
        $boxes = $this->service->listPublic($supplierId);

        $candidates = [];
        foreach ($boxes as $box) {
            $canSend = false;
            foreach ($box['credentials'] as $cred) {
                $canSend = $canSend || $cred['can_send'] === true || $cred['can_send'] === null;
            }
            $candidates[] = [
                'id' => $box['id'], 'label' => $box['label'], 'db_id' => $box['db_id'], 'db_type' => $box['db_type'],
                'environment' => $box['environment'], 'is_active' => $box['is_active'], 'can_send' => $canSend,
            ];
        }

        return [
            'submission' => [
                'id' => $submission['id'], 'form_code' => $submission['form_code'], 'form_name' => self::formName($submission['form_code']),
                'period_label' => self::periodLabel($submission), 'validation_status' => $submission['validation_status'],
            ],
            'recipient' => $office,
            'annotation' => $this->annotation($submission, $supplier),
            'file_name' => self::fileName($submission),
            'file_size' => $submission['xml_size_bytes'],
            'boxes' => $candidates,
            'recommended_box_id' => $this->recommendedBox($boxes),
            'blocked_reason' => self::blockedReason($submission),
            'warnings' => $this->warnings($submission, $office, null),
            'previous_sends' => $this->messages->activeSendsOfSubmission($submissionId, $supplierId),
        ];
    }

    /**
     * Stage the filing for confirmation. The caller then shows the summary and, only after the user's click, calls
     * {@see DataboxSendService::send()} with the returned token.
     *
     * @param array{recipient_box_id?:?string, annotation?:?string} $overrides
     * @return array{token:string, expires_at:string, summary:array<string,mixed>}
     * @throws DataboxException
     */
    public function prepare(int $supplierId, int $userId, int $submissionId, int $boxId, array $overrides = []): array
    {
        $submission = $this->requireSubmission($supplierId, $submissionId);
        $blocked = self::blockedReason($submission);
        if ($blocked !== null) {
            throw new DataboxException($blocked, self::BLOCKED_MESSAGES[$blocked], 409);
        }
        $box = $this->service->requireBox($supplierId, $boxId);
        $supplier = $this->supplierRow($supplierId);
        $office = $this->officeFor($submission, $supplier);

        $recipient = DataboxId::normalize((string) ($overrides['recipient_box_id'] ?? '')) ?: ($office['box_id'] ?? '');
        if ($recipient === '') {
            throw new DataboxException('no_recipient', 'Pro finanční úřad tohoto podání není v číselníku datová schránka. Zadejte ID datové schránky úřadu ručně.', 422);
        }
        $annotation = trim((string) ($overrides['annotation'] ?? '')) ?: $this->annotation($submission, $supplier);

        $previous = $this->messages->activeSendsOfSubmission($submissionId, $supplierId);
        $context = [
            'recipient_name' => $recipient === ($office['box_id'] ?? null) ? $office['name'] : null,
            'is_tax_office' => TaxOfficeCodebook::isTaxOfficeBox($recipient),
            'warnings' => $this->warnings($submission, $office, $box, $recipient),
            'previous_sends' => $previous,
            // A filing that was never validated against the EPO schema may be ineffective: sending it takes its own confirmation.
            'requires_unvalidated_confirmation' => self::isUnvalidated($submission),
            'form' => [
                'form_code' => $submission['form_code'], 'form_name' => self::formName($submission['form_code']),
                'period' => self::periodLabel($submission), 'xml_sha256' => $submission['xml_sha256'], 'submission_id' => $submission['id'],
            ],
        ];
        return $this->sender->prepare(
            $supplierId,
            $userId,
            $boxId,
            ['recipient_box_id' => $recipient, 'annotation' => $annotation],
            [['name' => self::fileName($submission), 'bytes' => (string) $submission['xml_content']]],
            $submissionId,
            $context,
        );
    }

    /**
     * @param list<int> $submissionIds
     * @return array<int, list<array<string,mixed>>> submission id -> messages that carried it
     */
    public function linksFor(array $submissionIds, int $supplierId): array
    {
        return $this->messages->linksForSubmissions($submissionIds, $supplierId);
    }

    // ---- derived values (static: unit-testable) ----

    public static function formName(string $formCode): string
    {
        return self::FORM_NAMES[$formCode] ?? strtoupper($formCode);
    }

    /** @param array<string,mixed> $submission */
    public static function periodLabel(array $submission): string
    {
        if ($submission['period_month'] !== null) {
            return sprintf('%02d/%d', $submission['period_month'], $submission['period_year']);
        }
        if ($submission['period_quarter'] !== null) {
            return sprintf('%d. čtvrtletí %d', $submission['period_quarter'], $submission['period_year']);
        }
        return (string) $submission['period_year'];
    }

    /** @param array<string,mixed> $submission e.g. dphdp3-2026-03.xml, dphkh1-2026-Q1.xml, dpfdp5-2025.xml */
    public static function fileName(array $submission): string
    {
        $period = (string) $submission['period_year'];
        if ($submission['period_month'] !== null) {
            $period .= sprintf('-%02d', $submission['period_month']);
        } elseif ($submission['period_quarter'] !== null) {
            $period .= '-Q' . $submission['period_quarter'];
        }
        return strtolower((string) $submission['form_code']) . '-' . $period . '.xml';
    }

    /** The EPO schema validation never ran for this XML (so the filing may be ineffective). @param array<string,mixed> $submission */
    private static function isUnvalidated(array $submission): bool
    {
        return $submission['validation_status'] === 'skipped';
    }

    /**
     * Why this archived XML must not be sent at all: it failed the EPO schema validation, or it is no longer the XML whose
     * SHA-256 was stored when it was archived.
     *
     * @param array<string,mixed> $submission
     * @return 'xsd_failed'|'integrity_failed'|null
     */
    private static function blockedReason(array $submission): ?string
    {
        if ($submission['validation_status'] === 'failed') {
            return 'xsd_failed';
        }
        $intact = hash_equals(strtolower((string) $submission['xml_sha256']), hash('sha256', (string) $submission['xml_content']));
        return $intact ? null : 'integrity_failed';
    }

    /** The `c_ufo` the XML names, or null. */
    public static function officeCodeFromXml(string $xml): ?string
    {
        try {
            $doc = IsdsResponseParser::load($xml); // refuses DTDs, so no entity expansion
        } catch (IsdsException) {
            return null;
        }
        $value = trim((new \DOMXPath($doc))->query('//@c_ufo')->item(0)?->nodeValue ?? '');
        return $value !== '' ? $value : null;
    }

    // ---- internals ----

    /** @return array<string,mixed> @throws DataboxException */
    private function requireSubmission(int $supplierId, int $submissionId): array
    {
        return $this->submissions->find($submissionId, $supplierId)
            ?? throw new DataboxException('not_found', 'Archivované podání nenalezeno.', 404);
    }

    /** @return array{financial_office_code:?string, dic:?string, ic:?string} */
    private function supplierRow(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT financial_office_code, dic, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['financial_office_code' => null, 'dic' => null, 'ic' => null];
    }

    /**
     * The tax office of the filing: the `c_ufo` inside the XML wins (that is what the filing says), the supplier's setting
     * is the fallback for archives without it.
     *
     * @param array<string,mixed> $submission
     * @param array{financial_office_code:?string, dic:?string, ic:?string} $supplier
     * @return array{c_ufo:?string, name:?string, box_id:?string, source:string}
     */
    private function officeFor(array $submission, array $supplier): array
    {
        $cUfo = self::officeCodeFromXml((string) $submission['xml_content']);
        $source = 'xml';
        if ($cUfo === null) {
            $cUfo = $supplier['financial_office_code'] ?: null;
            $source = 'supplier';
        }
        $office = TaxOfficeCodebook::forUfo($cUfo);
        return [
            'c_ufo' => $cUfo,
            'name' => $office['name'] ?? null,
            'box_id' => $office['box_id'] ?? null,
            'source' => $office !== null ? $source : 'none',
        ];
    }

    /**
     * @param array<string,mixed> $submission
     * @param array{financial_office_code:?string, dic:?string, ic:?string} $supplier
     */
    private function annotation(array $submission, array $supplier): string
    {
        $who = trim((string) $supplier['dic']) !== '' ? 'DIČ ' . $supplier['dic'] : (trim((string) $supplier['ic']) !== '' ? 'IČO ' . $supplier['ic'] : '');
        return trim(self::formName((string) $submission['form_code']) . ' za ' . self::periodLabel($submission) . ($who !== '' ? ', ' . $who : ''));
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $office
     * @param array<string,mixed>|null $box a stored box (not the public view)
     * @return list<string> warning codes
     */
    private function warnings(array $submission, array $office, ?array $box, ?string $recipient = null): array
    {
        $w = [];
        if (self::isUnvalidated($submission)) {
            $w[] = 'xsd_skipped';
        }
        if ($office['box_id'] === null) {
            $w[] = 'no_office';
        } elseif ($recipient !== null && $recipient !== $office['box_id']) {
            $w[] = 'recipient_differs_from_office';
        }
        if ($box !== null) {
            if ($box['db_type'] === 'FO') {
                $w[] = 'personal_box';
            }
            if ($box['environment'] === IsdsEnvironment::TEST) {
                $w[] = 'test_environment';
            }
        }
        return $w;
    }

    /**
     * Prefer the business box (PFO / PO ...) of the production environment, then any active box.
     *
     * @param list<array<string,mixed>> $boxes public views
     */
    private function recommendedBox(array $boxes): ?int
    {
        $best = null;
        $bestScore = -1;
        foreach ($boxes as $box) {
            if (!$box['is_active'] || $box['environment'] !== IsdsEnvironment::PRODUCTION) {
                continue;
            }
            $score = ($box['db_type'] === 'FO') ? 1 : 2;
            if ($score > $bestScore) {
                $best = $box['id'];
                $bestScore = $score;
            }
        }
        return $best;
    }
}
