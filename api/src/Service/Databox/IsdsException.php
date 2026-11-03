<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Failure of an ISDS call, with a stable machine reason and a Czech message that is safe to show to the user
 * (never contains the password or the Authorization header).
 *
 * Reasons:
 *  - network            no HTTP response received
 *  - bad_credentials    HTTP 401 (wrong user name / password, expired password, registered certificate missing)
 *  - account_locked     HTTP 401 with "Login blocked until" (too many failed attempts) - see {@see blockedUntil()}
 *  - ip_blocked         HTTP 401 with the ISDS security-mechanism refusal page (address blocked)
 *  - maintenance        HTTP 503 (planned outage, ISDS answers a static page)
 *  - server_error       other HTTP 5xx / SOAP fault
 *  - http_error         any other non-200 status
 *  - invalid_response   the body is not the expected SOAP / XML
 *  - forbidden          the ISDS user lacks the privilege (1004, 1218)
 *  - not_delivered      a received message cannot be downloaded before it is delivered (1222)
 *  - personal_delivery  addressed "do vlastnich rukou" and not delivered (1178)
 *  - message_deleted    the message was already deleted in ISDS (1219)
 *  - message_not_ready  a just-sent message is not downloadable yet (1229)
 *  - rate_limited       ISDS throttling (3008, 3009, 3013, 3014)
 *  - delivery_running   delivery on login takes too long, retry (3006)
 *  - invalid_input      the request was rejected (bad character, attachment, recipient ...)
 *  - isds_error         any other non-zero status
 */
final class IsdsException extends \RuntimeException
{
    /** Status codes the specification documents, mapped to [reason, Czech message]. */
    private const KNOWN = [
        '1004' => ['forbidden', 'Přihlášený uživatel datové schránky nemá oprávnění k této akci.'],
        '1218' => ['forbidden', 'Přihlášený uživatel datové schránky nemá k této zprávě oprávnění.'],
        '1178' => ['personal_delivery', 'Zpráva je určena výhradně do vlastních rukou adresáta a dosud nebyla doručena. Otevřete ji v klientském portálu datových schránek.'],
        '1201' => ['invalid_input', 'Ze znepřístupněné datové schránky nelze odesílat zprávy.'],
        '1211' => ['invalid_input', 'Zpráva nepatří do této datové schránky.'],
        '1219' => ['message_deleted', 'Zpráva už byla v systému datových schránek smazána (90 dní po doručení, nejdéle 3 roky po dodání).'],
        '1222' => ['not_delivered', 'Zpráva dosud nebyla doručena, proto ji nelze číst.'],
        '1225' => ['invalid_input', 'Text obsahuje neplatný znak.'],
        '1229' => ['message_not_ready', 'Odeslaná zpráva ještě není k dispozici ke stažení, zkuste to za chvíli.'],
        '1252' => ['invalid_input', 'Příloha byla zamítnuta antivirovou kontrolou.'],
        '1281' => ['invalid_input', 'Zvolená služba není určena pro tento typ zprávy.'],
        '1295' => ['invalid_input', 'Příloha je příliš velká.'],
        '2102' => ['invalid_input', 'Tento typ přihlášení nemá záznam uživatele (přihlášení systémovým certifikátem).'],
        '2103' => ['invalid_input', 'Služba nelze použít pro interního uživatele.'],
        '3006' => ['delivery_running', 'Doručování zpráv přihlášením trvá příliš dlouho, zkuste to znovu.'],
        '3008' => ['rate_limited', 'Datová schránka je dočasně přetížená paralelními požadavky, zkuste to později.'],
        '3009' => ['rate_limited', 'Datová schránka právě zpracovává jiný požadavek tohoto účtu, zkuste to později.'],
        '3013' => ['rate_limited', 'Práce s velkoobjemovými zprávami je dočasně pozastavena, zkuste to později.'],
        '3014' => ['rate_limited', 'Příliš mnoho asynchronních požadavků z jedné schránky, zkuste to později.'],
    ];

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $isdsCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $blockedUntilTime = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Non-zero ISDS status (dmStatus / dbStatus) -> exception. */
    public static function fromStatus(string $code, string $isdsMessage): self
    {
        if (isset(self::KNOWN[$code])) {
            [$reason, $message] = self::KNOWN[$code];
            return new self($reason, $message, $code);
        }
        $isdsMessage = trim(preg_replace('/\s+/u', ' ', $isdsMessage) ?? '');
        $suffix = $isdsMessage !== '' ? ' (' . mb_substr($isdsMessage, 0, 200) . ')' : '';
        return new self('isds_error', "Datová schránka odmítla požadavek, kód {$code}{$suffix}.", $code);
    }

    /** HTTP-level failure (no usable SOAP body). */
    public static function fromHttp(int $status, string $body): self
    {
        if ($status === 401) {
            if (preg_match('/Login blocked until:\s*([0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)/i', $body, $m) === 1) {
                return new self(
                    'account_locked',
                    'Přihlášení do datové schránky je dočasně zablokované kvůli opakovaným chybným pokusům (do ' . substr($m[1], 0, 5)
                    . '). Nezkoušejte hesla dál, počkejte na odblokování.',
                    null,
                    401,
                    $m[1],
                );
            }
            if (stripos($body, 'neumoznil prihlaseni') !== false) {
                return new self('ip_blocked', 'Systém datových schránek zablokoval přihlášení z adresy tohoto serveru. Kontaktujte infolinku 954 200 200.', null, 401);
            }
            return new self('bad_credentials', 'Přihlášení do datové schránky se nezdařilo: neplatné jméno nebo heslo (nebo vypršelé heslo / uživatel s registrovaným certifikátem).', null, 401);
        }
        if ($status === 503) {
            return new self('maintenance', 'Systém datových schránek je dočasně nedostupný (plánovaná odstávka). Zkuste to později.', null, 503);
        }
        if ($status >= 500) {
            return new self('server_error', "Systém datových schránek vrátil chybu (HTTP {$status}). Zkuste to později.", null, $status);
        }
        return new self('http_error', "Neočekávaná odpověď systému datových schránek (HTTP {$status}).", null, $status);
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self('invalid_response', 'Odpověď datové schránky se nepodařilo přečíst' . ($detail !== '' ? " ({$detail})" : '') . '.');
    }

    /**
     * End of an ISDS login block. ISDS reports only a time of day, so the date is resolved against $now: the next
     * occurrence of that time.
     */
    public function blockedUntil(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($this->blockedUntilTime === null) {
            return null;
        }
        $until = $now->modify('today ' . $this->blockedUntilTime);
        return $until > $now ? $until : $until->modify('+1 day');
    }

    /** True when retrying the same call later has a chance to succeed. */
    public function isTransient(): bool
    {
        return in_array($this->reason, ['network', 'maintenance', 'server_error', 'rate_limited', 'delivery_running', 'message_not_ready'], true);
    }

    /** True when the login itself is the problem, so no further call with these credentials should be made. */
    public function isAuthFailure(): bool
    {
        return in_array($this->reason, ['bad_credentials', 'account_locked', 'ip_blocked'], true);
    }
}
