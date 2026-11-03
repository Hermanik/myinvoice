<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Web service endpoints of the ISDS ("datove schranky") information system.
 *
 * Official sources (checked 2026-11-03):
 *  - Operating rules, chapter 5 (login of third-party applications):
 *    https://datovka.gov.cz/info/files/2245_Provozni_rad_ISDS_26_06_2026.pdf
 *  - WS_manipulace_s_datovymi_zpravami.pdf 3.8.1 (07/2026), chapters 1.2.1.1 - 1.2.1.4:
 *    base URL for Basic authentication is https://ws1.datovka.gov.cz, the public test environment is
 *    https://ws1.datovka-test.gov.cz; the service path is /DS/{dz|dx|df|DsManage}.
 *  - Production moved from mojedatovaschranka.cz to datovka.gov.cz on 2026-06-25 (developer news
 *    https://datovka.gov.cz/info/files/2244_Info_pro_vyvojare_2026_6_2.pdf); the old hosts keep working
 *    for now, but their end date is not set, so only the new domain is used here.
 */
final class IsdsEnvironment
{
    public const PRODUCTION = 'production';
    public const TEST = 'test';

    /** dz = message operations with content, dx = envelope/list/info operations, df = search, DsManage = login info. */
    public const SERVICE_MESSAGES = 'dz';
    public const SERVICE_INFO = 'dx';
    public const SERVICE_SEARCH = 'df';
    public const SERVICE_ACCESS = 'DsManage';

    private const BASE_URLS = [
        self::PRODUCTION => 'https://ws1.datovka.gov.cz',
        self::TEST => 'https://ws1.datovka-test.gov.cz',
    ];

    private const SERVICES = [
        self::SERVICE_MESSAGES, self::SERVICE_INFO, self::SERVICE_SEARCH, self::SERVICE_ACCESS,
    ];

    public static function isValid(string $environment): bool
    {
        return isset(self::BASE_URLS[$environment]);
    }

    public static function endpoint(string $environment, string $service): string
    {
        if (!self::isValid($environment)) {
            throw new \InvalidArgumentException("Unknown ISDS environment '{$environment}'.");
        }
        if (!in_array($service, self::SERVICES, true)) {
            throw new \InvalidArgumentException("Unknown ISDS service '{$service}'.");
        }
        return self::BASE_URLS[$environment] . '/DS/' . $service;
    }
}
