<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Databox\DeliveryDates;
use MyInvoice\Service\Mail\Mailer;

/**
 * The alert e-mail for one newly found data message: what arrived, when it is delivered by fiction at the latest, and that
 * it is NOT delivered until somebody opens it in the portal.
 */
class DataboxEmailNoticeAlert
{
    public const PORTAL_URL = 'https://www.datovka.gov.cz';
    private const MAX_SUBJECT_ANNOTATION = 150;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * @param array<string,mixed> $box the databoxes row (its supplier decides the branding)
     * @throws \Throwable when the mail cannot be sent
     */
    public function send(string $to, array $box, DatovkaNotice $notice, int $messageId, \DateTimeImmutable $arrivedAt, bool $isTaxOffice): void
    {
        $this->mailer->sendTemplate('databox_new_message', 'cs', [$to], $this->variables($box, $notice, $messageId, $arrivedAt, $isTaxOffice));
    }

    /**
     * What the alert template shows.
     *
     * @param array<string,mixed> $box the databoxes row
     * @return array<string,mixed>
     */
    public function variables(array $box, DatovkaNotice $notice, int $messageId, \DateTimeImmutable $arrivedAt, bool $isTaxOffice): array
    {
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $annotation = self::singleLine((string) $notice->annotation);

        return [
            'subject' => ($isTaxOffice ? '[Finanční úřad] ' : '') . 'Nová datová zpráva: '
                . ($annotation !== '' ? mb_substr($annotation, 0, self::MAX_SUBJECT_ANNOTATION) : '(bez věci)'),
            'box_label' => (string) $box['label'],
            'box_db_id' => (string) $box['db_id'],
            'sender_name' => $notice->senderName,
            'sender_box_id' => $notice->senderBoxId,
            'annotation' => $annotation,
            'dm_id' => $notice->dmId,
            'is_tax_office' => $isTaxOffice,
            'arrived_at' => $arrivedAt->format('j. n. Y H:i'),
            'fiction_date' => DeliveryDates::fictionDate($arrivedAt)->format('j. n. Y'),
            'portal_link' => self::PORTAL_URL,
            'app_link' => $appUrl !== '' ? "{$appUrl}/databox?box={$box['id']}&message={$messageId}" : '',
            'supplier' => $this->supplierFooter((int) $box['supplier_id']),
        ];
    }

    /** One line without control characters: the text lands in a mail header. */
    private static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
    }

    /** Branding and footer of the supplier the box belongs to. @return array<string,mixed>|null */
    private function supplierFooter(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, COALESCE(bp.display_name, s.display_name) AS display_name,
                    COALESCE(bp.tagline, s.tagline) AS tagline, s.street, s.city, s.zip,
                    COALESCE(bp.email, s.email) AS email, COALESCE(bp.phone, s.phone) AS phone,
                    COALESCE(bp.web, s.web) AS web,
                    COALESCE(bp.branding_enabled, s.email_branding_enabled) AS email_branding_enabled,
                    COALESCE(bp.accent_color, s.email_accent_color) AS email_accent_color,
                    COALESCE(bp.logo_path, s.logo_path) AS logo_path, bp.id AS branding_profile_id,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN branding_profiles bp ON s.branding_profiles_enabled = 1 AND bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id AND bp.is_active = 1
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
        $row['email_accent_color'] = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['accent_soft'] = AccentColor::emailBackground($row['email_branding_enabled'], $row['email_accent_color']);
        $row['logo_path'] = $row['logo_path'] ?: null;
        return $row;
    }
}
