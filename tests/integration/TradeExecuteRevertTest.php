<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Illuminate\Contracts\Events\Dispatcher;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\ShopClaim;
use Ramon\PointSystem\Model\Trade;
use Ramon\PointSystem\Model\TradeItem;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\TradeRepository;

/**
 * Exercises the full trade pipeline (execute + admin revert) against a real
 * schema, with the UNIQUE (user_id, item_type, item_id) key and InnoDB
 * behaviour in play.
 *
 * Debug repros (2026-09-11 session):
 *   - PS-TRD-001: revert() flipped the ShopClaim.user_id back to the original
 *     owner. When the donor KEPT copies of the item (claim row still exists),
 *     the flip collides with the UNIQUE key → QueryException → HTTP 500 and
 *     the trade silently stays completed.
 *   - PS-TRD-002: revert() left the post-trade owner's
 *     `current_*_decoration_id` pointer dangling when they had equipped the
 *     received item before the revert.
 */
class TradeExecuteRevertTest extends IntegrationTestCase
{
    private const TYPE = 'avatar_decoration';
    private const ITEM = 7;
    private const A = 1;
    private const B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        UserPoints::create(['user_id' => self::A, 'balance' => 100, 'lifetime' => 100]);
        UserPoints::create(['user_id' => self::B, 'balance' => 50, 'lifetime' => 50]);
    }

    private function repo(): TradeRepository
    {
        $events = new class implements Dispatcher {
            public function listen($events, $listener = null) {}
            public function subscribe($subscriber) {}
            public function until($event, $payload = []) {}
            public function dispatch($event, $payload = [], $halt = false) { return $event; }
            public function push($event, $payload = []) {}
            public function flush($event) {}
            public function forget($event) {}
            public function forgetPushed() {}
            public function hasListeners($event): bool { return false; }
        };

        return new TradeRepository($this->connection(), $events);
    }

    private function admin(): \Flarum\User\User
    {
        $admin = new \Flarum\User\User();
        $admin->id = 9;
        return $admin;
    }

    private function makeTrade(int $initiatorPoints, int $recipientPoints): Trade
    {
        $trade = Trade::create([
            'initiator_id' => self::A,
            'recipient_id' => self::B,
            'initiator_points' => $initiatorPoints,
            'recipient_points' => $recipientPoints,
            'initiator_accepted' => true,
            'recipient_accepted' => true,
            'status' => Trade::STATUS_PENDING,
        ]);

        TradeItem::create([
            'trade_id' => $trade->id,
            'owner_id' => self::A,
            'item_type' => self::TYPE,
            'item_id' => self::ITEM,
        ]);

        return $trade->refresh();
    }

    private function claim(int $userId, int $qty): ShopClaim
    {
        return ShopClaim::create([
            'user_id' => $userId,
            'item_type' => self::TYPE,
            'item_id' => self::ITEM,
            'quantity' => $qty,
            'price_paid' => 100,
        ]);
    }

    private function claimOf(int $userId): ?ShopClaim
    {
        return ShopClaim::query()
            ->where('user_id', $userId)
            ->where('item_type', self::TYPE)
            ->where('item_id', self::ITEM)
            ->first();
    }

    /**
     * PS-TRD-001: donor keeps copies (qty 3 → 2 after execute). The revert
     * must move the single traded copy back, not flip a row onto an existing
     * UNIQUE (user_id, item_type, item_id) sibling.
     */
    public function test_revert_restores_donor_copies_without_unique_violation(): void
    {
        $this->claim(self::A, 3);
        $trade = $this->makeTrade(20, 40);

        $repo = $this->repo();

        $trade = $repo->execute($trade->id);
        $this->assertSame(Trade::STATUS_COMPLETED, $trade->status);
        $this->assertSame(2, (int) $this->claimOf(self::A)->quantity);
        $this->assertSame(1, (int) $this->claimOf(self::B)->quantity);
        $this->assertSame(120, (int) UserPoints::query()->find(self::A)->balance);
        $this->assertSame(30, (int) UserPoints::query()->find(self::B)->balance);

        $trade = $repo->revert($trade->id, $this->admin());

        $this->assertSame(Trade::STATUS_CANCELLED, $trade->status);
        $this->assertSame(3, (int) $this->claimOf(self::A)->quantity, 'donor copies restored');
        $this->assertNull($this->claimOf(self::B), 'recipient copy returned');
        $this->assertSame(100, (int) UserPoints::query()->find(self::A)->balance);
        $this->assertSame(50, (int) UserPoints::query()->find(self::B)->balance);

        $this->assertSame(
            1,
            PointTransaction::query()->where('user_id', self::A)->where('reason', 'trade')->count(),
        );
        $this->assertSame(
            1,
            PointTransaction::query()->where('user_id', self::A)->where('reason', 'trade_reverted')->count(),
        );
    }

    /**
     * PS-TRD-002: the post-trade owner equipped the received frame; after the
     * revert they no longer own it, so the equipped pointer must be cleared
     * (execute() performs the mirror cleanup for the donor — revert had none).
     */
    public function test_revert_clears_equipped_pointer_of_post_trade_owner(): void
    {
        $this->claim(self::A, 1);
        $trade = $this->makeTrade(0, 0);

        $repo = $this->repo();

        $trade = $repo->execute($trade->id);
        $this->assertNull($this->claimOf(self::A), 'single copy moved away');

        $b = UserPoints::query()->find(self::B);
        $b->current_avatar_decoration_id = self::ITEM;
        $b->save();

        $repo->revert($trade->id, $this->admin());

        $this->assertNotNull($this->claimOf(self::A), 'copy returned to original owner');
        $this->assertNull($this->claimOf(self::B), 'recipient row gone');

        $b = UserPoints::query()->find(self::B);
        $this->assertNull(
            $b->current_avatar_decoration_id,
            'post-trade owner must not keep an equipped pointer to an item they no longer own',
        );
    }

    /**
     * PS-TRD-003: guard — reverting after the post-trade owner re-traded the
     * item must refuse with `item_re_traded`, never steal from a third party.
     */
    public function test_revert_refuses_when_item_moved_on(): void
    {
        $this->claim(self::A, 1);
        $trade = $this->makeTrade(0, 0);

        $repo = $this->repo();

        $trade = $repo->execute($trade->id);

        // Simulate the item moving on: the post-trade owner's claim is gone.
        $this->claimOf(self::B)->delete();

        $this->expectException(\Flarum\Foundation\ValidationException::class);
        $repo->revert($trade->id, $this->admin());
    }
}
