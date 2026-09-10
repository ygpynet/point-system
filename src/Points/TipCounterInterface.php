<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

/**
 * Counts how many tips a user has sent recently. Extracted behind an interface
 * so the rate-limit decision ({@see TipRateLimiter}) is unit-testable without a
 * database — tests inject a fake, production binds {@see PostTipCounter}.
 */
interface TipCounterInterface
{
    /**
     * Number of tips the user has sent within the rolling rate-limit window.
     */
    public function recentCount(int $userId): int;
}
