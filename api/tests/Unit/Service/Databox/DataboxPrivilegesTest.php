<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\DataboxPrivileges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The legal heart of the feature: which ISDS logins may be used for the unattended sync without delivering messages.
 * WS spec 1.4: a user without PRIVIL_READ_NON_PERSONAL (1) and PRIVIL_READ_ALL (2) does not deliver when listing.
 */
final class DataboxPrivilegesTest extends TestCase
{
    /** @return array<string, array{int, string, bool}> mask, sync safety, delivers on list */
    public static function masks(): array
    {
        return [
            'list only (the recommended sync login)' => [8, 'safe', false],
            'list + send + search (an action login that cannot read)' => [4 + 8 + 16, 'safe', false],
            'list + read' => [1 + 8, 'delivers', true],
            'list + read personal' => [2 + 8, 'delivers', true],
            'everything' => [255, 'delivers', true],
            'read without list' => [1, 'no_list', true],
            'send only' => [4, 'no_list', false],
            'nothing' => [0, 'no_list', false],
        ];
    }

    #[DataProvider('masks')]
    public function testSyncSafety(int $mask, string $safety, bool $delivers): void
    {
        $p = new DataboxPrivileges($mask);
        self::assertSame($safety, $p->syncSafety());
        self::assertSame($delivers, $p->deliversOnList());
    }

    public function testCapabilities(): void
    {
        $p = new DataboxPrivileges(DataboxPrivileges::VIEW_INFO | DataboxPrivileges::CREATE_DM | DataboxPrivileges::SEARCH_DB);
        self::assertTrue($p->canList());
        self::assertTrue($p->canSend());
        self::assertTrue($p->canSearch());
        self::assertFalse($p->canReadReceived());
        self::assertFalse($p->canReadPersonal());
    }

    public function testReadAllImpliesReadingButNotTheOtherWayRound(): void
    {
        self::assertTrue((new DataboxPrivileges(2))->canReadReceived());
        self::assertTrue((new DataboxPrivileges(2))->canReadPersonal());
        self::assertTrue((new DataboxPrivileges(1))->canReadReceived());
        self::assertFalse((new DataboxPrivileges(1))->canReadPersonal());
    }

    public function testOwnersHoldEveryRightRegardlessOfTheReportedMask(): void
    {
        foreach (['PRIMARY_USER', 'LIQUIDATOR', 'GUARDIAN', 'RECEIVER'] as $type) {
            $p = DataboxPrivileges::fromUserInfo($type, 0);
            self::assertSame(255, $p->mask, $type);
            self::assertSame('delivers', $p->syncSafety(), $type);
        }
    }

    public function testEntrustedUsersAndAdministratorsUseTheExplicitMask(): void
    {
        self::assertSame('safe', DataboxPrivileges::fromUserInfo('ENTRUSTED_USER', 8)->syncSafety());
        self::assertSame('safe', DataboxPrivileges::fromUserInfo('ADMINISTRATOR', 8 | 32)->syncSafety());
        self::assertSame('no_list', DataboxPrivileges::fromUserInfo('ENTRUSTED_USER', null)->syncSafety());
    }

    public function testLabelsMatchThePortalWording(): void
    {
        self::assertSame(
            ['Číst přijaté zprávy', 'Posílat zprávy a číst odeslané', 'Zobrazovat seznamy, historii a doručenky'],
            (new DataboxPrivileges(1 + 4 + 8))->labels(),
        );
        self::assertSame(['Zobrazovat seznamy, historii a doručenky'], (new DataboxPrivileges(8 | 32))->labels(), 'the admin bit has no portal checkbox');
    }
}
