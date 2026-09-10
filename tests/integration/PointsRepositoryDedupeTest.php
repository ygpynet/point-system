<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Integration test for the dedupe_key defence.
 *
 * The PS-IDE-FIX bug fix relies on TWO things holding at the database level:
 *   1. the `point_system_tx_dedupe` unique index actually rejects a second row
 *      with the same (reason|reference_type|reference_id) for an idempotent
 *      reason, and
 *   2. PointsRepository::isUniqueViolation() correctly classifies that failure
 *      so award() can roll the transaction back instead of 500-ing.
 *
 * This boots an in-memory SQLite database, runs the REAL migration `up()`
 * closures (so the schema + index match production exactly), and exercises the
 * unique index directly through the same PointTransaction model award() uses.
 *
 * Requires the pdo_sqlite extension; skips automatically when unavailable.
 */
class PointsRepositoryDedupeTest extends TestCase
{
    private Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite extension is required for this integration test');
        }
    }

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            // The real migrations declare a FK to `users`; with constraints off
            // we don't need a users table for this focused test.
            'foreign_key_constraints' => false,
        ]);
        $this->capsule->bootEloquent();
        $this->capsule->setAsGlobal();

        $this->runMigrations();
    }

    /**
     * Run the real Flarum-style migration closures (array with up/down) in the
     * order they ship, so the schema + dedupe index are production-identical.
     */
    private function runMigrations(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();

        foreach ([
            '2026_05_12_000002_create_point_system_transactions_table.php',
            '2026_08_25_000010_add_dedupe_key_to_point_system_transactions.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../migrations/' . $file;
            ($migration['up'])($schema);
        }
    }

    private function repo(): PointsRepository
    {
        // Minimal fakes: points enabled, no daily cap, auto-groups disabled so
        // syncAutoGroups() short-circuits without needing the groups tables.
        $settings = new class implements \Flarum\Settings\SettingsRepositoryInterface {
            public function get(string $key, $default = null)
            {
                return match ($key) {
                    'point-system.enabled' => true,
                    'point-system.daily_earn_cap' => 0,
                    'point-system.auto_group_enabled' => false,
                    default => $default,
                };
            }
            public function set(string $key, $value): void {}
            public function delete(string $key): void {}
            public function all(): array { return []; }
        };

        $dispatcher = new class implements \Illuminate\Contracts\Events\Dispatcher {
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

        return new PointsRepository($settings, $dispatcher, $this->capsule->getConnection());
    }

    private function insert(string $reason, ?string $dedupeKey, int $referenceId = 5): PointTransaction
    {
        return PointTransaction::create([
            'user_id' => 1,
            'amount' => 10,
            'reason' => $reason,
            'reference_type' => 'discussion',
            'reference_id' => $referenceId,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    public function test_duplicate_idempotent_award_is_rejected_by_unique_index(): void
    {
        $key = 'discussion.started|discussion|5';

        $this->assertNotNull($this->insert('discussion.started', $key)->id);

        $threw = false;
        try {
            $this->insert('discussion.started', $key);
        } catch (QueryException $e) {
            $threw = true;

            // The repository's classifier must recognise this as a unique violation
            // so award() can roll the transaction back rather than 500-ing.
            $method = new \ReflectionMethod(PointsRepository::class, 'isUniqueViolation');
            $method->setAccessible(true);
            $this->assertTrue($method->invoke($this->repo(), $e));
        }

        $this->assertTrue($threw, 'a second row with the same dedupe_key must be rejected');
        $this->assertSame(1, PointTransaction::count(), 'exactly one ledger row must persist');
    }

    public function test_different_reference_allows_two_rows(): void
    {
        $this->insert('discussion.started', 'discussion.started|discussion|5', 5);
        $this->insert('discussion.started', 'discussion.started|discussion|6', 6);

        $this->assertSame(2, PointTransaction::count());
    }

    public function test_null_dedupe_key_allows_repeats(): void
    {
        // Non-idempotent reasons (e.g. tips) must NOT be de-duplicated.
        $this->insert('tip.out', null, 9);
        $this->insert('tip.out', null, 9);

        $this->assertSame(2, PointTransaction::count());
    }
}
