<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Ramon\PointSystem\Event\PointsAwarded;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;
use Ramon\PointSystem\Support\PointReason;

/**
 * PS-EVT-003 (debug session 2026-09-11).
 *
 * transfer() used to delegate to the public deduct()/award() legs. deduct()
 * flushes its own PointsAwarded events as soon as its leg commits (a nested
 * savepoint at that point), so when the CREDIT leg failed afterwards the
 * whole transfer rolled back — but listeners (notifications, realtime push)
 * had already been told the sender lost points that never actually moved.
 *
 * The fix routes transfer() through deductWithin()/awardWithin() and flushes
 * both models' pending events only after the outer transaction commits. The
 * injected-failure subclass below overrides BOTH the pre-fix seam (award)
 * and the post-fix seam (awardWithin) so this test proves the leak pre-fix
 * and stays green post-fix.
 */
class TransferEventLeakTest extends IntegrationTestCase
{
    public function test_failed_transfer_must_not_leak_debit_events(): void
    {
        $records = [];
        $spy = $this->spyDispatcher($records);

        UserPoints::create(['user_id' => 1, 'balance' => 500, 'lifetime' => 500]);

        $from = new User();
        $from->id = 1;
        $to = new User();
        $to->id = 2;

        $repo = new class(
            $this->defaultSettings(),
            $spy,
            $this->connection(),
            PointReason::builtIn(),
        ) extends PointsRepository {
            public function award(
                User $user,
                int $amount,
                string $reason,
                ?string $referenceType = null,
                ?int $referenceId = null,
                ?array $meta = null,
                bool $bypassCap = false,
            ): ?PointTransaction {
                throw new \DomainException('simulated award-leg failure');
            }

            protected function awardWithin(
                User $user,
                int $amount,
                string $reason,
                ?string $referenceType,
                ?int $referenceId,
                ?array $meta,
                bool $bypassCap,
                bool $touchLifetime = true,
            ): array {
                throw new \DomainException('simulated award-leg failure');
            }
        };

        $failed = false;
        try {
            $repo->transfer($from, $to, 100, 'tip', 'post', 5);
        } catch (\DomainException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'the simulated award-leg failure must propagate');

        $leaked = array_filter($records, fn ($e) => $e instanceof PointsAwarded);
        $this->assertSame(
            [],
            array_values($leaked),
            'a rolled-back transfer must never announce the debit to listeners',
        );

        // And the sender's balance must be intact after the rollback.
        $this->assertSame(500, (int) UserPoints::query()->where('user_id', 1)->first()->balance);
        $this->assertSame(0, PointTransaction::count());
    }
}
