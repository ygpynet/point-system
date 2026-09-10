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

    public function test_built_in_has_nineteen_reasons(): void
    {
        $this->assertCount(19, $this->reasons());
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
            $this->assertContains($data['category'], [
                PointReason::CATEGORY_EARN,
                PointReason::CATEGORY_SPEND,
                PointReason::CATEGORY_TRANSFER,
                PointReason::CATEGORY_ADMIN,
            ], "category for {$code} is valid");
            $this->assertIsBool($data['countsTowardDailyCap']);
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
        // crashes on a raw code (PS-REA-002 / reasonLabel fallback).
        $this->assertSame('ygpynet-point-system.reasons.this.does.not.exist', $r->labelKey('this.does.not.exist'));
    }
}
