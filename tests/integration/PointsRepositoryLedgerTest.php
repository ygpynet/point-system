<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Full-repository ledger tests against a real schema — the DB-bound cases
 * that the unit suite carries as incomplete skeletons:
 *
 *   PS-IDE-001  idempotent award records exactly one row
 *   PS-IDE-003  non-idempotent award is unconstrained
 *   PS-CAP-001  daily earn cap clamps the total
 *   PS-TRF-001  transfer moves both balances + writes both audit rows
 *   PS-TRV-001  revert with a meta filter reverses exactly the targeted credit
 *               (the like.received precision fix)
 */
class PointsRepositoryLedgerTest extends IntegrationTestCase
{
    private function user(int $id): User
    {
        $u = new User();
        $u->id = $id;
        return $u;
    }

    /** @param array<string, mixed> $overrides */
    private function repoWith(array $overrides = []): PointsRepository
    {
        $settings = new class($overrides) implements SettingsRepositoryInterface {
            /** @param array<string, mixed> $overrides */
            public function __construct(protected array $overrides) {}
            public function get(string $key, mixed $default = null): mixed
            {
                if (array_key_exists($key, $this->overrides)) {
                    return $this->overrides[$key];
                }
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

        return $this->makePointsRepository($settings);
    }

    public function test_idempotent_award_records_exactly_one_row(): void
    {
        $repo = $this->repoWith();
        $user = $this->user(1);

        $first = $repo->award($user, 50, 'discussion.started', 'discussion', 5);
        $second = $repo->award($user, 50, 'discussion.started', 'discussion', 5);

        $this->assertNotNull($first);
        $this->assertNull($second, 'duplicate entity-scoped award must be skipped');

        $points = UserPoints::query()->where('user_id', 1)->first();
        $this->assertSame(50, (int) $points->balance, 'balance credited once, not twice');
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_non_idempotent_award_is_unconstrained(): void
    {
        $repo = $this->repoWith();
        $user = $this->user(1);

        $repo->award($user, 10, 'checkin', 'user', 1, ['day' => 1]);
        $repo->award($user, 10, 'checkin', 'user', 1, ['day' => 2]);

        $this->assertSame(2, PointTransaction::count());
        $this->assertSame(20, (int) UserPoints::query()->where('user_id', 1)->first()->balance);
    }

    public function test_daily_earn_cap_clamps_total(): void
    {
        $repo = $this->repoWith(['point-system.daily_earn_cap' => 500]);
        $user = $this->user(1);

        $repo->award($user, 300, 'checkin', 'user', 1);
        $repo->award($user, 300, 'checkin', 'user', 1);

        $points = UserPoints::query()->where('user_id', 1)->first();
        $this->assertSame(500, (int) $points->balance, 'second award clamped to the remaining budget');
        $this->assertSame(500, (int) $points->daily_earned);
        $this->assertSame(2, PointTransaction::count(), 'clamped award still writes its (smaller) row');
    }

    public function test_transfer_moves_both_balances_and_writes_both_rows(): void
    {
        UserPoints::create(['user_id' => 1, 'balance' => 300, 'lifetime' => 300]);
        UserPoints::create(['user_id' => 2, 'balance' => 0, 'lifetime' => 0]);

        $this->makePointsRepository()->transfer($this->user(1), $this->user(2), 100, 'tip', 'post', 9);

        $this->assertSame(200, (int) UserPoints::query()->where('user_id', 1)->first()->balance);
        $this->assertSame(100, (int) UserPoints::query()->where('user_id', 2)->first()->balance);

        $out = PointTransaction::query()->where('user_id', 1)->where('reason', 'tip.out')->first();
        $in = PointTransaction::query()->where('user_id', 2)->where('reason', 'tip.in')->first();
        $this->assertSame(-100, (int) $out->amount);
        $this->assertSame(100, (int) $in->amount);

        $this->assertSame(0, (int) UserPoints::query()->where('user_id', 2)->first()->lifetime,
            'received points are not lifetime earnings');
    }

    public function test_revert_with_meta_filter_hits_only_the_targeted_credit(): void
    {
        $repo = $this->repoWith();
        $author = $this->user(1);

        // Two likes on the same post — two separate like.received credits
        // distinguished only by the stamped liker_id meta.
        $repo->award($author, 2, 'like.received', 'post', 42, ['liker_id' => 7]);
        $repo->award($author, 2, 'like.received', 'post', 42, ['liker_id' => 8]);
        $this->assertSame(4, (int) UserPoints::query()->where('user_id', 1)->first()->balance);

        // Liker 7 un-likes: exactly THEIR credit must be reversed.
        $repo->revert($author, 'like.received', 'post', 42, null, ['liker_id' => 7]);

        $this->assertSame(2, (int) UserPoints::query()->where('user_id', 1)->first()->balance,
            'liker 8 credit intact, liker 7 credit reversed');

        $revert = PointTransaction::query()->where('reason', 'like.received.revert')->first();
        $this->assertNotNull($revert);
        $this->assertSame(-2, (int) $revert->amount);

        // Meta filter for a liker who never credited must be a no-op.
        $repo->revert($author, 'like.received', 'post', 42, null, ['liker_id' => 999]);
        $this->assertSame(2, (int) UserPoints::query()->where('user_id', 1)->first()->balance);
    }
}
