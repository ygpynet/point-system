<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Repository;

use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Ramon\PointSystem\Event\PointsAwarded;
use Ramon\PointSystem\Exception\DuplicateTransactionException;
use Ramon\PointSystem\Support\DayBoundary;
use Ramon\PointSystem\Support\PointReason;
use Ramon\PointSystem\Model\GroupOffer;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Points\PointsRepositoryInterface;

/**
 * Central service for all point operations.
 *
 * - award(): atomically credits points (lifetime + balance) and writes a tx row
 * - deduct(): atomically removes balance points (does NOT reduce lifetime)
 * - revert(): reverses a prior credit (used when a like is undone, etc.)
 * - syncAutoGroups(): keeps user's groups in line with their lifetime points
 *
 * Events live on the UserPoints model (via EventGeneratorTrait). They are
 * raised inside the same transaction as the state change and flushed once
 * the row is saved, so a rolled-back transaction never leaks a stale event.
 */
class PointsRepository implements PointsRepositoryInterface
{
    use DispatchEventsTrait;

    /**
     * Cache da lista de GroupOffers `is_auto` ordenada por threshold.
     *
     * Cada award/revert/deduct chama {@see syncAutoGroups}, que por sua vez
     * relia uma `SELECT * FROM point_system_group_offers WHERE is_auto = 1`
     * no banco — um burst de pontos (like + post + login bonus na mesma
     * request) acionava 3+ leituras idênticas. Memoizamos por instância;
     * worker leak é aceitável porque o conjunto é minúsculo e admin
     * editando offers durante o load é evento raro e auto-corretivo
     * (o próximo award no worker seguinte pega a versão nova). Limpar
     * manualmente via {@see clearAutoOffersCache} quando um admin endpoint
     * souber que mexeu na tabela.
     *
     * @var \Illuminate\Database\Eloquent\Collection<int, GroupOffer>|null
     */
    private ?\Illuminate\Database\Eloquent\Collection $cachedAutoOffers = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
        protected ConnectionInterface $db,
        protected PointReason $reasons,
    ) {}

    /**
     * Invalida o cache em memória de offers `is_auto`. Útil em endpoints
     * admin (CreateGroupOffer / UpdateGroupOffer) para que workers
     * de longa vida (Octane / queue) peguem a edição na próxima sync.
     */
    public function clearAutoOffersCache(): void
    {
        $this->cachedAutoOffers = null;
    }

    public function getOrCreate(User $user): UserPoints
    {
        return UserPoints::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'lifetime' => 0],
        );
    }

    /**
     * Relê a linha de pontos sob `SELECT … FOR UPDATE`. Só faz sentido dentro
     * de uma transação — todos os chamadores aqui rodam dentro de uma.
     *
     * Sem o lock, dois créditos concorrentes no mesmo usuário (like e post
     * chegando juntos, ou um bulk award cruzando com atividade normal) leem o
     * mesmo `lifetime`, cada um soma sobre a leitura obsoleta e o último
     * `save()` sobrescreve o primeiro — o lost update clássico. É o mesmo
     * padrão que os fluxos de trade e de loja já aplicam antes de mexer no
     * saldo. Em `deduct` o lock também fecha a janela entre a checagem de
     * saldo e o débito, que permitiria gastar além do disponível.
     */
    protected function getOrCreateForUpdate(User $user): UserPoints
    {
        $points = $this->getOrCreate($user);

        return UserPoints::query()
            ->whereKey($points->getKey())
            ->lockForUpdate()
            ->first() ?? $points;
    }

    /**
     * Is this a reason that must never be awarded twice for the same reference?
     * Delegates to the PointReason registry: built-in entity-scoped reasons
     * declare `idempotent = true` there, and third-party earners can register
     * their own codes with the same flag instead of editing this class.
     */
    private function isIdempotentReason(string $reason): bool
    {
        return $this->reasons->isIdempotent($reason);
    }

    /**
     * Credit a user with points. Updates both lifetime and balance, logs a
     * transaction row, then syncs auto-groups.
     *
     * Returns the transaction row (null if amount was zero / system disabled /
     * already awarded for an idempotent reason+reference).
     */
    public function award(
        User $user,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $meta = null,
        bool $bypassCap = false,
    ): ?PointTransaction {
        if ($amount <= 0 || ! $this->isEnabled()) {
            return null;
        }

        $tx = null;
        try {
            [$points, $tx] = $this->db->transaction(
                fn () => $this->awardWithin($user, $amount, $reason, $referenceType, $referenceId, $meta, $bypassCap)
            );

            $this->dispatchEventsFor($points);
        } catch (\Ramon\PointSystem\Exception\DuplicateTransactionException $e) {
            // Transaction rolled back as a duplicate credit: nothing to persist,
            // nothing to announce.
            return null;
        }

        return $tx;
    }

    /**
     * The award leg WITHOUT its own transaction or event flush — one atomic
     * unit of cap-checking, idempotency-gating, crediting and ledger writing.
     * Callers: award() (wraps in a transaction, flushes events on commit) and
     * transfer() (runs both legs inside ONE transaction so a mid-transfer
     * failure can never leave exactly one side changed, and so no leg's events
     * leak to listeners before the whole unit has committed).
     *
     * @return array{0: UserPoints, 1: PointTransaction|null}
     */
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
        $tx = null;
        $points = $this->getOrCreateForUpdate($user);

        // Daily earning cap. Resets on the server's calendar day
        // (DayBoundary), exactly like the check-in streak. Admin manual
        // grants pass $bypassCap = true and are never limited.
        $effective = $amount;
        if (! $bypassCap) {
            $cap = $this->settingInt('point-system.daily_earn_cap', 0);
            if ($cap > 0) {
                $today = DayBoundary::today();
                if ($points->daily_earned_date !== $today) {
                    $points->daily_earned = 0;
                    $points->daily_earned_date = $today;
                }
                $remaining = max(0, $cap - (int) $points->daily_earned);
                if ($remaining <= 0) {
                    return [$points, null];
                }
                $effective = min($amount, $remaining);
            }
        }

        // Idempotency guard. Runs under the row lock so a concurrent
        // re-dispatch can't slip a second credit between the check and the
        // write. Only reasons registered with idempotent=true participate
        // (see PointReason::isIdempotent); like awards are exempt.
        if ($referenceType !== null && $referenceId !== null && $this->isIdempotentReason($reason)) {
            $already = PointTransaction::where('user_id', $user->id)
                ->where('reason', $reason)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('amount', '>', 0)
                ->exists();

            if ($already) {
                return [$points, null];
            }
        }

        // Deterministic key for entity-scoped reasons so the DB unique index
        // (point_system_tx_dedupe) can reject a duplicate credit that slips
        // past the existence guard under a concurrent / retried dispatch.
        $dedupeKey = ($referenceType !== null && $referenceId !== null && $this->isIdempotentReason($reason))
            ? "{$reason}|{$referenceType}|{$referenceId}"
            : null;

        // Lifetime tracks points EARNED through forum activity. Transfer
        // credits are a movement, not an achievement — the docblock of
        // transfer() already documented that intent, and TradeRepository's
        // point movement never touches lifetime either. Feeding received
        // tips into lifetime silently pushed users toward auto-group tier
        // thresholds for something they were given, not earned.
        if ($touchLifetime) {
            $points->lifetime += $effective;
        }
        $points->balance  += $effective;
        $points->daily_earned += $effective;
        $points->raise(new PointsAwarded($user, $effective, $reason));
        $points->save();

        // The unique index is the last line of defense: if a duplicate still
        // reaches here (same-row race that slipped past the existence guard),
        // the create throws. We deliberately re-throw a marker so the whole
        // DB transaction aborts — rolling back the balance/daily-cap increment
        // performed just above. Treating the duplicate as "already credited"
        // (no row, no balance change) is only correct if the increment is
        // also undone; swallowing the exception here would commit the orphaned
        // balance bump and let a retried dispatch double-credit.
        try {
            $tx = PointTransaction::create([
                'user_id' => $user->id,
                'amount' => $effective,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'meta' => $meta,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            throw new \Ramon\PointSystem\Exception\DuplicateTransactionException();
        }

        $this->syncAutoGroups($user, $points);

        return [$points, $tx];
    }

    /**
     * Reverse a prior credit. Lifetime CAN drop here because the original action
     * was undone (e.g. user un-liked a post). Skips if no matching credit exists.
     *
     * Targeting:
     *   - $transactionId given → revert exactly that row (must belong to the
     *     user and be a positive credit); silently skips otherwise.
     *   - $metaFilter given → pick the most recent matching credit whose meta
     *     carries every key/value pair. This is how like.received stays
     *     precise: the award stamps meta['liker_id'], so un-liking reverses
     *     THAT liker's credit instead of whatever credit happens to be newest.
     *   - neither → most recent matching credit (legacy behaviour).
     */
    public function revert(
        User $user,
        string $reason,
        string $referenceType,
        int $referenceId,
        ?int $transactionId = null,
        ?array $metaFilter = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $points = $this->db->transaction(function () use ($user, $reason, $referenceType, $referenceId, $transactionId, $metaFilter) {
            $query = PointTransaction::where('user_id', $user->id)
                ->where('reason', $reason)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('amount', '>', 0);

            if ($transactionId !== null) {
                $tx = $query->where('id', $transactionId)->first();
            } else {
                $candidates = $query->orderByDesc('id')->limit(50)->get();
                $tx = $candidates->first(
                    fn (PointTransaction $row) => self::metaMatches($row->meta, $metaFilter)
                );
            }

            if (! $tx) {
                return null;
            }

            $points = $this->getOrCreateForUpdate($user);
            $points->lifetime = max(0, $points->lifetime - $tx->amount);
            $points->balance  = max(0, $points->balance - $tx->amount);
            $points->raise(new PointsAwarded($user, -$tx->amount, $reason.'.revert'));
            $points->save();

            PointTransaction::create([
                'user_id' => $user->id,
                'amount' => -$tx->amount,
                'reason' => $reason.'.revert',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $this->syncAutoGroups($user, $points);

            return $points;
        });

        if ($points) {
            $this->dispatchEventsFor($points);
        }
    }

    private static function metaMatches(?array $meta, ?array $filter): bool
    {
        if ($filter === null || $filter === []) {
            return true;
        }
        if ($meta === null) {
            return false;
        }
        foreach ($filter as $key => $value) {
            if (($meta[$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Spend balance points. Throws \DomainException when balance is insufficient.
     * Lifetime is NOT touched.
     */
    public function deduct(
        User $user,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): PointTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        [$points, $tx] = $this->db->transaction(
            fn () => $this->deductWithin($user, $amount, $reason, $referenceType, $referenceId)
        );

        $this->dispatchEventsFor($points);

        return $tx;
    }

    /**
     * The debit leg WITHOUT its own transaction or event flush — see
     * {@see awardWithin()} for the rationale.
     *
     * @return array{0: UserPoints, 1: PointTransaction}
     */
    protected function deductWithin(
        User $user,
        int $amount,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
    ): array {
        $points = $this->getOrCreateForUpdate($user);
        if ($points->balance < $amount) {
            throw new \DomainException('Insufficient point balance');
        }
        $points->balance -= $amount;
        $points->raise(new PointsAwarded($user, -$amount, $reason));
        $points->save();

        $tx = PointTransaction::create([
            'user_id' => $user->id,
            'amount' => -$amount,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return [$points, $tx];
    }

    /**
     * Atomically move points from one user to another. The sender's balance is
     * checked and debited inside the SAME database transaction that credits the
     * receiver, so a failure mid-transfer can never leave exactly one side
     * changed. The receiver's credit bypasses the daily cap — received points
     * aren't "earned" through activity.
     *
     * Runs {@see deductWithin()} + {@see awardWithin()} inside one transaction
     * instead of the public deduct()/award() legs: those flush their own
     * PointsAwarded events as soon as each leg commits, which — inside the
     * outer transfer transaction — announced the debit to listeners even when
     * the credit leg failed afterwards and everything rolled back (PS-EVT-003).
     * Both models' pending events now flush only after the whole unit commits.
     */
    public function transfer(
        User $from,
        User $to,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        if ($amount <= 0 || ! $this->isEnabled()) {
            return;
        }

        [$fromPoints, $toPoints] = $this->db->transaction(function () use ($from, $to, $amount, $reason, $referenceType, $referenceId) {
            // deductWithin() raises DomainException on shortfall, which rolls
            // back the whole transfer so the receiver is never credited for a
            // payment that didn't go through.
            [$fromPoints] = $this->deductWithin($from, $amount, $reason.'.out', $referenceType, $referenceId);
            [$toPoints] = $this->awardWithin($to, $amount, $reason.'.in', $referenceType, $referenceId, null, true, false);

            return [$fromPoints, $toPoints];
        });

        $this->dispatchEventsFor($fromPoints);
        $this->dispatchEventsFor($toPoints);
    }

    /**
     * Walk the auto-enabled group offers (ordered by points_required asc) and
     * attach the user to every offer they qualify for. Only offers with
     * is_auto=true participate: purchase-only offers are never auto-attached
     * and never auto-detached. Lifetime drops below the threshold of an
     * is_auto offer will detach the user from that group.
     */
    public function syncAutoGroups(User $user, ?UserPoints $points = null): void
    {
        if (! (bool) $this->settings->get('point-system.auto_group_enabled', true)) {
            return;
        }

        $points ??= $this->getOrCreate($user);
        $lifetime = $points->lifetime;

        // Cache memoizado — ver docblock de $cachedAutoOffers.
        $offers = $this->cachedAutoOffers ??= GroupOffer::where('is_enabled', true)
            ->where('is_auto', true)
            ->orderBy('points_required')
            ->get();

        $managedGroupIds = $offers->pluck('group_id')->all();
        if (empty($managedGroupIds)) {
            return;
        }

        $qualifyingIds = $offers
            ->filter(fn ($o) => $lifetime >= $o->points_required)
            ->pluck('group_id')
            ->all();

        $currentIds = $user->groups()->pluck('groups.id')->all();

        $toAdd    = array_diff($qualifyingIds, $currentIds);
        $toRemove = array_intersect(array_diff($managedGroupIds, $qualifyingIds), $currentIds);

        if ($toAdd) {
            $user->groups()->attach($toAdd);
        }
        if ($toRemove) {
            $user->groups()->detach($toRemove);
        }
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('point-system.enabled', true);
    }

    /**
     * True when the exception is a unique-constraint violation, resolved per
     * database driver instead of by substring-guessing the message. The
     * dedupe_key unique index is the last line of defense against double
     * credits, so a false negative here would let a retried dispatch commit
     * an orphaned balance bump; a false positive would abort a legitimate
     * credit. Unknown drivers keep the generic heuristic.
     */
    private function isUniqueViolation(\Throwable $e): bool
    {
        $code = (int) ($e->getCode() ?? 0);
        $msg = strtolower($e->getMessage());

        $driver = method_exists($this->db, 'getDriverName')
            ? strtolower((string) $this->db->getDriverName())
            : '';

        return match ($driver) {
            'mysql' => $code === 23000 || $code === 1062
                || str_contains($msg, 'duplicate entry')
                || str_contains($msg, '1062'),
            'pgsql' => $code === 23505
                || str_contains($msg, 'duplicate key value'),
            'sqlite' => $code === 23000 || $code === 19
                || str_contains($msg, 'unique constraint')
                || str_contains($msg, 'is not unique'),
            'sqlsrv' => $code === 23000 || $code === 2601 || $code === 2627
                || str_contains($msg, '2601')
                || str_contains($msg, '2627'),
            default => $code === 23000
                || str_contains($msg, 'unique')
                || str_contains($msg, 'duplicate'),
        };
    }

    public function settingInt(string $key, int $default = 0): int
    {
        return (int) $this->settings->get($key, $default);
    }
}
