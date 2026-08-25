<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

use Carbon\Carbon;
use Ramon\PointSystem\Model\PostTip;

/**
 * Production {@see TipCounterInterface}: counts the sender's tip rows in the
 * trailing rolling hour. The `point_system_post_tips` table is small and the
 * query is indexed on `sender_id`, so this stays cheap even at high tip volume.
 */
class PostTipCounter implements TipCounterInterface
{
    public function recentCount(int $userId): int
    {
        return PostTip::where('sender_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
    }
}
