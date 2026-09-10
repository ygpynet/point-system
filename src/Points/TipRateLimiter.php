<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Abuse guard for the tip endpoint.
 *
 * Without a limit a user can script unlimited transfers to an alt account
 * (points laundering) or spam the author with tip notifications. We count the
 * sender's tips in the trailing rolling hour and reject once the configured
 * per-user cap is reached.
 *
 * The cap lives in `point-system.tip_hourly_limit`; `0` (the default) disables
 * the guard so existing deployments keep their current behaviour until an admin
 * opts in. The actual count is delegated to {@see TipCounterInterface} so the
 * decision logic is testable without touching the database.
 */
class TipRateLimiter
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private TipCounterInterface $counter,
    ) {}

    /**
     * Configured per-user hourly tip cap (0 = unlimited).
     */
    public function limit(): int
    {
        return (int) $this->settings->get('point-system.tip_hourly_limit', 0);
    }

    /**
     * Tips the user has sent in the current rolling-hour window.
     */
    public function recentCount(int $userId): int
    {
        return $this->counter->recentCount($userId);
    }

    /**
     * True when the user has exhausted their rolling-hour tip allowance.
     */
    public function isLimited(int $userId): bool
    {
        $limit = $this->limit();

        return $limit > 0 && $this->counter->recentCount($userId) >= $limit;
    }
}
