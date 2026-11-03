<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * An approved draft that the approval flow held back (`invoice.auto_issue_held`) must stay visible on the
 * dashboard until it is issued — nothing was issued or sent, so nobody would notice otherwise.
 */
#[Group('integration')]
final class HeldDraftActionItemTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private CrmAggregationService $crm;
    private int $client = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->crm = $this->fx->container->get(CrmAggregationService::class);
        $this->client = $this->fx->client('Zadržený koncept', null, 'CZ11111118');
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    /** @return array<string,mixed>|null */
    private function item(?int $userId = null): ?array
    {
        foreach ($this->crm->actionItems($this->fx->supplierId, $userId, new \DateTimeImmutable('2099-06-10'))['items'] as $item) {
            if ($item['type'] === 'held_drafts') {
                return $item;
            }
        }
        return null;
    }

    private function heldDraft(string $number, bool $held = true): int
    {
        $id = $this->fx->sale($number, $this->client, '2099-06-10', 1000, 0, 0, '1', 'draft');
        if ($held) {
            $this->fx->log('invoice.auto_issue_held', 'invoice', $id, '2099-06-10 09:00:00', ['reason' => 'payer_zero_rated', 'auto_reason' => 'work_report_approved']);
        }
        return $id;
    }

    public function testAHeldDraftIsOfferedWithALinkToItUntilItIsIssued(): void
    {
        self::assertNull($this->item());
        $id = $this->heldDraft('HELD-1');

        $item = $this->item();

        self::assertNotNull($item);
        self::assertSame('Faktura čeká na kontrolu sazby DPH', $item['title']);
        self::assertSame("/invoices/{$id}", $item['link']);
        self::assertSame(1, $item['count']);
        self::assertSame('high', $item['severity']);

        $this->fx->pdo->exec("UPDATE invoices SET status = 'issued' WHERE id = {$id}");
        self::assertNull($this->item(), 'issued -> the item is gone');
    }

    public function testADraftThatWasNeverHeldIsNotListed(): void
    {
        $id = $this->heldDraft('PLAIN-DRAFT', held: false);
        $this->fx->log('invoice.updated', 'invoice', $id, '2099-06-10 09:00:00');   // other activity does not count

        self::assertNull($this->item());
    }

    public function testSeveralHeldDraftsPointToTheDraftList(): void
    {
        $this->heldDraft('HELD-A');
        $this->heldDraft('HELD-B');

        $item = $this->item();

        self::assertSame(2, $item['count']);
        self::assertSame('Faktury čekají na kontrolu sazby DPH', $item['title']);
        self::assertSame('/invoices?status=draft', $item['link']);
        self::assertStringContainsString('2 schválených faktur', $item['hint']);
    }

    public function testTheItemCanBeDismissedAndRestored(): void
    {
        $this->heldDraft('HELD-DISMISS');
        $supplier = $this->fx->supplierId;
        $user = $this->fx->userId;

        try {
            $this->crm->dismissActionItem($supplier, $user, 'held_drafts', 'forever');
            self::assertNull($this->item($user));
        } finally {
            $this->crm->restoreActionItem($supplier, $user, 'held_drafts');
        }
        self::assertNotNull($this->item($user));

        // "Historical" hides what is held now, but a draft held later shows up again.
        try {
            $this->crm->dismissActionItem($supplier, $user, 'held_drafts', 'historical');
            self::assertNull($this->item($user));
            $later = $this->heldDraft('HELD-LATER');
            self::assertSame("/invoices/{$later}", $this->item($user)['link']);
        } finally {
            $this->crm->restoreActionItem($supplier, $user, 'held_drafts');
        }
    }
}
