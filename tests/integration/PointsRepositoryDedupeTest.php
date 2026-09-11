<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Illuminate\Database\QueryException;
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
 * Runs the REAL migrations via IntegrationTestCase (SQLite in-memory by
 * default, or a MySQL scratch database with PS_DB_DRIVER=mysql), so the
 * schema + index are production-identical.
 */
class PointsRepositoryDedupeTest extends IntegrationTestCase
{
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
            $this->assertTrue($method->invoke($this->makePointsRepository(), $e));
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
