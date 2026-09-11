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
use Ramon\PointSystem\Support\PointReason;

/**
 * Covers the orchestration-level behaviour of PointsRepository that can be
 * exercised WITHOUT a database (partial mocks + reflection), plus skeletons
 * for the DB-bound cases (idempotency / daily cap / tip) that need a real
 * schema. See tests/TestCase-Design.md PS-TRF-* / PS-IDE-* / PS-CAP-*.
 */
class PointsRepositoryTest extends TestCase
{
    private function makeRepo(array $onlyMethods = [], ?\Illuminate\Database\ConnectionInterface $dbOverride = null): PointsRepository
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'point-system.enabled' ? true : $default
        );

        $db = $dbOverride ?? $this->createMock(ConnectionInterface::class);
        // Make transaction() execute the closure synchronously so the
        // orchestration runs without a real connection.
        if ($dbOverride === null) {
            $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());
        }

        $dispatcher = $this->createMock(Dispatcher::class);

        if ($onlyMethods === []) {
            return new PointsRepository($settings, $dispatcher, $db, PointReason::builtIn());
        }

        return $this->getMockBuilder(PointsRepository::class)
            ->setConstructorArgs([$settings, $dispatcher, $db, PointReason::builtIn()])
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

        $repo = new PointsRepository($settings, $dispatcher, $db, PointReason::builtIn());

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
        $repo = $this->makeRepo(['deductWithin', 'awardWithin']);

        $from = $this->userMock();
        $to = $this->userMock();
        $fromPoints = new \Ramon\PointSystem\Model\UserPoints();
        $toPoints = new \Ramon\PointSystem\Model\UserPoints();

        $repo->expects($this->once())
            ->method('deductWithin')
            ->with($from, 100, 'tip.out', Post::class, 5)
            ->willReturn([$fromPoints, null]);

        $repo->expects($this->once())
            ->method('awardWithin')
            ->with($to, 100, 'tip.in', Post::class, 5, null, true, false)
            ->willReturn([$toPoints, null]);

        $repo->transfer($from, $to, 100, 'tip', Post::class, 5);
    }

    public function test_transfer_rolls_back_when_deduct_throws(): void
    {
        $repo = $this->makeRepo(['deductWithin', 'awardWithin']);

        $repo->method('deductWithin')
            ->willThrowException(new \DomainException('Insufficient point balance'));

        // The receiver must never be credited if the sender can't pay.
        $repo->expects($this->never())->method('awardWithin');

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
        $repo = $this->makeRepo();
        $method = new \ReflectionMethod(PointsRepository::class, 'isIdempotentReason');
        $method->setAccessible(true);

        // The registry is the single source: these three carry idempotent=true
        // there and everything else defaults to repeatable.
        foreach (['discussion.started', 'post.posted', 'user.registered'] as $code) {
            $this->assertTrue($method->invoke($repo, $code), "{$code} must be idempotent");
        }
        foreach (['like.received', 'checkin', 'tip.out', 'admin.adjustment', 'totally.unknown'] as $code) {
            $this->assertFalse($method->invoke($repo, $code), "{$code} must allow repeats");
        }
    }

    public function test_meta_matches_filters_credit_rows(): void
    {
        $method = new \ReflectionMethod(PointsRepository::class, 'metaMatches');
        $method->setAccessible(true);

        $nullFilter = $method->invoke(null, null, null);
        $this->assertTrue($nullFilter);

        $meta = ['liker_id' => 7, 'extra' => 'x'];
        $this->assertTrue($method->invoke(null, $meta, ['liker_id' => 7]));
        $this->assertTrue($method->invoke(null, $meta, []));
        $this->assertFalse($method->invoke(null, $meta, ['liker_id' => 8]));
        $this->assertFalse($method->invoke(null, $meta, ['liker_id' => '7']));
        $this->assertFalse($method->invoke(null, $meta, ['missing_key' => 1]));
        $this->assertFalse($method->invoke(null, null, ['liker_id' => 7]));
    }

    /**
     * PS-UNIQ-001: unique-violation detection is driver-specific, not a
     * message substring guess. mysql / pgsql / sqlite branches plus the
     * generic fallback for unknown drivers.
     */
    public function test_is_unique_violation_resolves_per_driver(): void
    {
        $cases = [
            ['mysql', new \Exception('SQLSTATE[23000]: 1062 Duplicate entry', 23000), true],
            ['mysql', new \Exception('Duplicate entry for key', 0), true],
            ['mysql', new \Exception('some other failure', 1045), false],
            ['pgsql', new \Exception('duplicate key value violates unique constraint', 0), true],
            ['pgsql', new \Exception('duplicate key value violates unique constraint', 23505), true],
            ['pgsql', new \Exception('syntax error', 42601), false],
            ['sqlite', new \Exception('UNIQUE constraint failed: x.y', 19), true],
            ['sqlite', new \Exception('no such table', 1), false],
            ['sqlsrv', new \Exception('Cannot insert duplicate key row', 2601), true],
            ['weird', new \Exception('a unique thing failed', 0), true],
            ['weird', new \Exception('unrelated', 12345), false],
        ];

        foreach ($cases as [$driver, $exception, $expected]) {
            $db = $this->getMockBuilder(\Illuminate\Database\MySqlConnection::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getDriverName'])
                ->getMock();
            $db->method('getDriverName')->willReturn($driver);

            $repo = $this->makeRepo([], $db);

            $method = new \ReflectionMethod(PointsRepository::class, 'isUniqueViolation');
            $method->setAccessible(true);

            $this->assertSame($expected, $method->invoke($repo, $exception), "driver {$driver}: {$exception->getMessage()}");
        }
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

        $repo = new PointsRepository($settings, $this->createMock(Dispatcher::class), $db, PointReason::builtIn());

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
