<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Exception\DuplicateTransactionException;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Covers the orchestration-level behaviour of PointsRepository that can be
 * exercised WITHOUT a database (partial mocks + reflection), plus skeletons
 * for the DB-bound cases (idempotency / daily cap / tip) that need a real
 * schema. See tests/TestCase-Design.md PS-TRF-* / PS-IDE-* / PS-CAP-*.
 */
class PointsRepositoryTest extends TestCase
{
    private function makeRepo(array $onlyMethods = []): PointsRepository
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'point-system.enabled' ? true : $default
        );

        $db = $this->createMock(ConnectionInterface::class);
        // Make transaction() execute the closure synchronously so the
        // orchestration runs without a real connection.
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        $dispatcher = $this->createMock(Dispatcher::class);

        if ($onlyMethods === []) {
            return new PointsRepository($settings, $dispatcher, $db);
        }

        return $this->getMockBuilder(PointsRepository::class)
            ->setConstructorArgs([$settings, $dispatcher, $db])
            ->onlyMethods($onlyMethods)
            ->getMock();
    }

    private function userMock(): User
    {
        return $this->createMock(User::class);
    }

    public function test_award_returns_null_when_amount_non_positive(): void
    {
        $repo = $this->makeRepo();

        $this->assertNull($repo->award($this->userMock(), 0, 'checkin'));
        $this->assertNull($repo->award($this->userMock(), -5, 'checkin'));
    }

    public function test_award_returns_null_when_disabled(): void
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'point-system.enabled' ? false : $default
        );
        $db = $this->createMock(ConnectionInterface::class);
        $dispatcher = $this->createMock(Dispatcher::class);

        $repo = new PointsRepository($settings, $dispatcher, $db);

        $this->assertNull($repo->award($this->userMock(), 100, 'checkin'));
    }

    public function test_deduct_throws_on_non_positive_amount(): void
    {
        $repo = $this->makeRepo();

        $this->expectException(\InvalidArgumentException::class);
        $repo->deduct($this->userMock(), 0, 'tip.out');
    }

    public function test_transfer_calls_deduct_and_award_with_suffixed_reasons(): void
    {
        $repo = $this->makeRepo(['deduct', 'award']);

        $from = $this->userMock();
        $to = $this->userMock();

        $repo->expects($this->once())
            ->method('deduct')
            ->with($from, 100, 'tip.out', Post::class, 5);

        $repo->expects($this->once())
            ->method('award')
            ->with($to, 100, 'tip.in', Post::class, 5, null, true);

        $repo->transfer($from, $to, 100, 'tip', Post::class, 5);
    }

    public function test_transfer_rolls_back_when_deduct_throws(): void
    {
        $repo = $this->makeRepo(['deduct', 'award']);

        $repo->method('deduct')
            ->willThrowException(new \DomainException('Insufficient point balance'));

        // The receiver must never be credited if the sender can't pay.
        $repo->expects($this->never())->method('award');

        $this->expectException(\DomainException::class);
        $repo->transfer($this->userMock(), $this->userMock(), 100, 'tip', Post::class, 5);
    }

    public function test_is_idempotent_reason_flags_entity_scoped_only(): void
    {
        $repo = $this->makeRepo();
        $method = new \ReflectionMethod(PointsRepository::class, 'isIdempotentReason');
        $method->setAccessible(true);

        // Entity-scoped, one-per-reference reasons.
        $this->assertTrue($method->invoke($repo, 'discussion.started'));
        $this->assertTrue($method->invoke($repo, 'post.posted'));
        $this->assertTrue($method->invoke($repo, 'user.registered'));

        // Repeated-allowed reasons and transfers must NOT be de-duplicated.
        $this->assertFalse($method->invoke($repo, 'like.received'));
        $this->assertFalse($method->invoke($repo, 'tip.out'));
        $this->assertFalse($method->invoke($repo, 'checkin'));
        $this->assertFalse($method->invoke($repo, 'totally.unknown'));
    }

    public function test_idempotent_reasons_constant_matches_documented_set(): void
    {
        $constant = (new \ReflectionClass(PointsRepository::class))
            ->getConstant('IDEMPOTENT_REASONS');

        $this->assertSame(
            ['discussion.started', 'post.posted', 'user.registered'],
            $constant
        );
    }

    /**
     * PS-IDE-FIX regression: when the dedupe_key unique index rejects a
     * duplicate insert, award() must roll the whole transaction back (i.e. not
     * commit an orphaned balance increment) and return null — never silently
     * persist a balance bump with no ledger row.
     */
    public function test_award_returns_null_when_dedupe_unique_index_rejects(): void
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'point-system.enabled' ? true : $default
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transaction')
            ->willThrowException(new DuplicateTransactionException());

        $repo = new PointsRepository($settings, $this->createMock(Dispatcher::class), $db);

        $this->assertNull($repo->award($this->userMock(), 10, 'checkin'));
    }

    // ---------------------------------------------------------------------
    // DB-bound skeletons — require a real schema (SQLite in-memory under a
    // Flarum test harness). Wire up tests/integration once the DB harness
    // exists; until then they are reported as "incomplete", not as failures.
    // ---------------------------------------------------------------------

    public function test_award_idempotent_duplicate_records_once(): void
    {
        // PS-IDE-001: two award() with the same (reason, reference_type,
        // reference_id) for an idempotent reason must produce exactly one row.
        $this->markTestIncomplete('Requires DB harness — see tests/TestCase-Design.md PS-IDE-001');
    }

    public function test_award_non_idempotent_allows_duplicates(): void
    {
        // PS-IDE-003: a non-idempotent reason (e.g. tip.out) must NOT be
        // constrained by the dedupe_key unique index (key is NULL).
        $this->markTestIncomplete('Requires DB harness — see tests/TestCase-Design.md PS-IDE-003');
    }

    public function test_daily_earn_cap_clamps_total(): void
    {
        // PS-CAP-001: with point-system.daily_earn_cap=500, cumulative awards
        // in a day must not exceed 500.
        $this->markTestIncomplete('Requires DB harness — see tests/TestCase-Design.md PS-CAP-001');
    }

    public function test_daily_cap_not_double_counted_for_idempotent(): void
    {
        // PS-CAP-002: a re-dispatched idempotent award must not re-consume the
        // daily cap budget.
        $this->markTestIncomplete('Requires DB harness — see tests/TestCase-Design.md PS-CAP-002');
    }
}
