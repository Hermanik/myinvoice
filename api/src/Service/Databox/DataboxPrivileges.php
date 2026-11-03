<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Privileges of one ISDS user (the `userPrivils` bitmask from GetUserInfoFromLogin2).
 *
 * Source: WS_manipulace_s_datovymi_zpravami.pdf 3.8.1, chapter 1.4 "Opravneni potrebna k jednotlivym operacim",
 * and the portal help https://datovka.gov.cz/info/cs/help/8.5.text.html ("Uzivatele").
 *
 *   1   PRIVIL_READ_NON_PERSONAL  "Cist prijate zpravy"
 *   2   PRIVIL_READ_ALL           "Cist prijate zpravy do vlastnich rukou"
 *   4   PRIVIL_CREATE_DM          "Posilat zpravy a cist odeslane"
 *   8   PRIVIL_VIEW_INFO          "Zobrazovat seznamy, historii a dorucenky"
 *   16  PRIVIL_SEARCH_DB          "Vyhledavat schranky"
 *   32  PRIVIL_OWNER_ADM          administration of the box (administrators)
 *   128 PRIVIL_ERASE_VAULT        "Mazat zpravy v trezoru"
 *
 * Delivery semantics (legal!): the operating rules count GetListOfReceivedMessages as "logging in to the box"
 * (https://datovka.gov.cz/info/files/2245_Provozni_rad_ISDS_26_06_2026.pdf, chapter 4). The call delivers the
 * messages the caller is allowed to READ (state 4/5 -> 6) and thereby starts legal deadlines. The specification
 * (WS spec 1.4) states that the access of an authorized person WITHOUT PRIVIL_READ_NON_PERSONAL and
 * PRIVIL_READ_ALL does not deliver messages when reading the list of received messages. A list-only user
 * (PRIVIL_VIEW_INFO only) therefore sees every envelope without delivering anything.
 */
final class DataboxPrivileges
{
    public const READ_NON_PERSONAL = 1;
    public const READ_ALL = 2;
    public const CREATE_DM = 4;
    public const VIEW_INFO = 8;
    public const SEARCH_DB = 16;
    public const OWNER_ADM = 32;
    public const ERASE_VAULT = 128;

    /** User types that hold every privilege automatically (WS spec 1.4: owners, statutory representatives ...). */
    private const FULL_ACCESS_USER_TYPES = ['PRIMARY_USER', 'LIQUIDATOR', 'GUARDIAN', 'RECEIVER'];

    /** Czech labels exactly as the ISDS portal calls the checkboxes (so the owner can match them). */
    private const LABELS = [
        self::READ_NON_PERSONAL => 'Číst přijaté zprávy',
        self::READ_ALL => 'Číst přijaté zprávy do vlastních rukou',
        self::CREATE_DM => 'Posílat zprávy a číst odeslané',
        self::VIEW_INFO => 'Zobrazovat seznamy, historii a doručenky',
        self::SEARCH_DB => 'Vyhledávat schránky',
        self::ERASE_VAULT => 'Mazat zprávy v trezoru',
    ];

    public function __construct(public readonly int $mask) {}

    /** Owners and statutory representatives have everything; others carry the explicit mask. */
    public static function fromUserInfo(?string $userType, ?int $mask): self
    {
        if ($userType !== null && in_array($userType, self::FULL_ACCESS_USER_TYPES, true)) {
            return new self(255);
        }
        return new self(max(0, (int) $mask));
    }

    public function has(int $flag): bool
    {
        return ($this->mask & $flag) === $flag;
    }

    public function canList(): bool
    {
        return $this->has(self::VIEW_INFO);
    }

    public function canReadReceived(): bool
    {
        return ($this->mask & (self::READ_NON_PERSONAL | self::READ_ALL)) !== 0;
    }

    public function canReadPersonal(): bool
    {
        return $this->has(self::READ_ALL);
    }

    public function canSend(): bool
    {
        return $this->has(self::CREATE_DM);
    }

    public function canSearch(): bool
    {
        return $this->has(self::SEARCH_DB);
    }

    /** True when listing received messages with this user legally delivers them. */
    public function deliversOnList(): bool
    {
        return $this->canReadReceived();
    }

    /** @return list<string> Czech labels of the granted rights */
    public function labels(): array
    {
        $out = [];
        foreach (self::LABELS as $flag => $label) {
            if ($this->has($flag)) {
                $out[] = $label;
            }
        }
        return $out;
    }

    /**
     * Whether a login with these rights is fit for the unattended background sync.
     *
     *  - 'safe'     lists envelopes and cannot read, so the sync never delivers a message
     *  - 'delivers' lists envelopes but can read, so every sync delivers new messages (legal deadlines start)
     *  - 'no_list'  cannot list at all (no "Zobrazovat seznamy" right)
     */
    public function syncSafety(): string
    {
        if (!$this->canList()) {
            return 'no_list';
        }
        return $this->deliversOnList() ? 'delivers' : 'safe';
    }
}
