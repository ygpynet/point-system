<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Support\PointReason;

/**
 * Covers PS-REA-001 / PS-REA-002 / PS-REA-003 from tests/TestCase-Design.md.
 *
 * Pure unit test — no database, no Flarum boot. Runs under the existing
 * `unit` test suite once phpunit is installed.
 */
class PointReasonTest extends TestCase
{
    private function reasons(): array
    {
        return PointReason::builtIn()->all();
    }

    public function test_built_in_covers_every_documented_code(): void
    {
        // Presence guard: each of these codes is written by real code paths
        // and must keep resolving to a label. (An exact-count assertion rotted
        // twice already — new reasons land here without updating a number.)
        $expected = [
            // Earning
            'discussion.started', 'post.posted', 'user.registered',
            'like.received', 'like.given', 'checkin', 'daily.login', 'tier.claim', 'shop.claim',
            'user.check_in', 'user.checkin_makeup',
            // Spending
            'shop.purchase', 'group.purchase', 'tip.out',
            // Transfers
            'tip.in',
            // Admin
            'admin.adjustment', 'pointSystem.manual', 'admin.bulk',
            // Trade
            'trade.credit', 'trade.debit',
            // Reverts
            'discussion.started.revert', 'post.posted.revert', 'user.registered.revert',
            'like.received.revert', 'like.given.revert',
            // Legacy / third-party
            'user.daily_login', 'giveaway.entry', 'trade',
        ];

        foreach ($expected as $code) {
            $this->assertArrayHasKey($code, $this->reasons(), "reason {$code} must stay registered");
        }
    }

    public function test_built_in_keys_are_unique(): void
    {
        $keys = array_keys($this->reasons());
        $this->assertSame($keys, array_unique($keys));
    }

    public function test_built_in_shape_is_consistent(): void
    {
        foreach ($this->reasons() as $code => $data) {
            $this->assertIsString($code);
            $this->assertArrayHasKey('label', $data);
            $this->assertArrayHasKey('category', $data);
            $this->assertArrayHasKey('countsTowardDailyCap', $data);
            $this->assertArrayHasKey('idempotent', $data);
            $this->assertContains($data['category'], [
                PointReason::CATEGORY_EARN,
                PointReason::CATEGORY_SPEND,
                PointReason::CATEGORY_TRANSFER,
                PointReason::CATEGORY_ADMIN,
            ], "category for {$code} is valid");
            $this->assertIsBool($data['countsTowardDailyCap']);
            $this->assertIsBool($data['idempotent']);
        }
    }

    public function test_category_and_cap_flags_for_known_reasons(): void
    {
        $r = PointReason::builtIn();

        // Earning reasons count toward the daily cap by default.
        $this->assertSame(PointReason::CATEGORY_EARN, $r->category('checkin'));
        $this->assertTrue($r->countsTowardDailyCap('checkin'));

        // Spending / transfer reasons must NOT count toward the daily cap.
        $this->assertSame(PointReason::CATEGORY_SPEND, $r->category('tip.out'));
        $this->assertFalse($r->countsTowardDailyCap('tip.out'));
        $this->assertSame(PointReason::CATEGORY_TRANSFER, $r->category('tip.in'));
        $this->assertFalse($r->countsTowardDailyCap('tip.in'));

        // Admin grants bypass the cap.
        $this->assertSame(PointReason::CATEGORY_ADMIN, $r->category('pointSystem.manual'));
        $this->assertFalse($r->countsTowardDailyCap('pointSystem.manual'));
    }

    public function test_unknown_reason_is_not_registered(): void
    {
        $r = PointReason::builtIn();

        $this->assertFalse($r->has('this.does.not.exist'));
        $this->assertNull($r->category('this.does.not.exist'));
        // Unknown codes still resolve to a translation key so the UI never
        // crashes on a raw code (PS-REA-002 / reasonLabel fallback). The key
        // carries the `lib.` segment so it reaches the frontend locale JS and
        // dots are normalized to underscores before the lookup.
        $this->assertSame('ygpynet-point-system.lib.reasons.this_does_not_exist', $r->labelKey('this.does.not.exist'));
    }

    public function test_entity_scoped_reasons_are_idempotent(): void
    {
        $r = PointReason::builtIn();

        // One discussion / one post / one registration: a repeated award for
        // the same reference is a duplicate (queue retry, re-fired event) and
        // the ledger must skip it.
        $this->assertTrue($r->isIdempotent('discussion.started'));
        $this->assertTrue($r->isIdempotent('post.posted'));
        $this->assertTrue($r->isIdempotent('user.registered'));

        // Repeatable reasons: a post can be liked many times; tips, claims
        // and admin adjustments are legitimately repeatable.
        foreach (['like.received', 'like.given', 'checkin', 'shop.claim', 'tip.out', 'tip.in', 'admin.adjustment'] as $code) {
            $this->assertFalse($r->isIdempotent($code), "{$code} must allow repeats");
        }

        // Unknown codes default to repeatable — a missing flag must never
        // silently drop a legitimate third-party award.
        $this->assertFalse($r->isIdempotent('acme.custom'));
    }

    public function test_registered_reason_can_declare_idempotency(): void
    {
        $r = PointReason::builtIn();

        $r->register('acme.once_per_entity', 'acme_once', PointReason::CATEGORY_EARN, true, true);

        $this->assertTrue($r->has('acme.once_per_entity'));
        $this->assertTrue($r->isIdempotent('acme.once_per_entity'));
    }
}
