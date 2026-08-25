<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Event;

use Flarum\User\User;
use Ramon\PointSystem\Model\PointTransaction;

/**
 * Fired once per persisted {@see PointTransaction}.
 *
 * Unlike {@see PointsAwarded} (which only travels on the UserPoints row for
 * auto-credits), this is the single hook for "a points ledger entry was
 * written" — covering manual awards, spends, reverts, and tips. Third-party
 * extensions listen here to build analytics, webhooks, or external sync
 * without depending on internal repository call sites.
 */
class PointsTransactionRecorded
{
    public function __construct(
        public PointTransaction $transaction,
        public ?User $user = null,
    ) {}
}
