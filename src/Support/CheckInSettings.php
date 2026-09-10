<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Central reader for the check-in settings plus the derived math shared by
 * the check-in / make-up endpoints and the serialized user attributes.
 *
 * Reward model — grows with the streak, then caps out:
 *
 *   reward(day) = base + min(day - 1, growthCapDays) * perDayExtra
 *
 * e.g. base=5, extra=1, cap=7 → d1=5, d2=6 … d8=12, then fixed at 12 forever.
 */
final class CheckInSettings
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
    ) {
    }

    /** Day-1 reward. 0 disables the whole feature. */
    public function base(): int
    {
        return max(0, (int) $this->settings->get('point-system.checkin_base_points', 5));
    }

    /** Extra points per consecutive day while growing. */
    public function perDayExtra(): int
    {
        return max(0, (int) $this->settings->get('point-system.checkin_per_day_extra', 1));
    }

    /** After this many days the daily reward stops growing (the cap). */
    public function growthCapDays(): int
    {
        return max(0, (int) $this->settings->get('point-system.checkin_growth_cap_days', 7));
    }

    /** Points spent per make-up check-in. 0 disables make-ups. */
    public function makeupCost(): int
    {
        return max(0, (int) $this->settings->get('point-system.checkin_makeup_cost', 10));
    }

    /** Max consecutive make-up check-ins between two real ones. */
    public function makeupMax(): int
    {
        return max(0, (int) $this->settings->get('point-system.checkin_makeup_max_streak', 3));
    }

    public function enabled(): bool
    {
        return $this->base() > 0;
    }

    public function makeupEnabled(): bool
    {
        return $this->enabled() && $this->makeupCost() > 0 && $this->makeupMax() > 0;
    }

    public function rewardForDay(int $day): int
    {
        return $this->base() + min(max(0, $day - 1), $this->growthCapDays()) * $this->perDayExtra();
    }

    /**
     * Can this row use a make-up right now? A gap must exist (the last
     * checked day is older than yesterday) AND the consecutive-makeup budget
     * must not only be non-exhausted but big enough to bridge the WHOLE gap.
     *
     * A make-up exists to reconnect the streak: the next real check-in
     * continues it only when the last stamped day is yesterday. Filling
     * days without reaching yesterday still leaves $last < yesterday, so
     * the streak resets to 1 anyway and the spent points buy nothing —
     * in that case the widget must NOT offer the button at all.
     */
    public function canMakeup(?UserPoints $row): bool
    {
        if (! $this->makeupEnabled() || $row === null || $row->last_checkin_date === null) {
            return false;
        }

        $last = $row->last_checkin_date;
        $yesterday = DayBoundary::yesterday();

        if ($last >= $yesterday) {
            return false;
        }

        /*
         * Days missing between the last checked day and yesterday — one paid
         * make-up each. Both strings parse as midnight-local in the SAME
         * server zone {@see DayBoundary} uses, so diffInDays is an exact
         * whole number and the int cast is lossless (Carbon 2 int / Carbon 3
         * float both fine).
         */
        $tz = DayBoundary::timezone();
        $gapDays = (int) Carbon::createFromFormat('Y-m-d', $last, $tz)
            ->startOfDay()
            ->diffInDays(Carbon::createFromFormat('Y-m-d', $yesterday, $tz)->startOfDay());

        $remaining = $this->makeupMax() - (int) $row->checkin_makeup_count;

        return $remaining >= $gapDays;
    }

    /**
     * Snapshot of everything the frontend widget needs, post-action. Kept in
     * one place so the two endpoints always agree on the shape.
     */
    public function stateFor(PointsRepository $points, $actor): array
    {
        $row = $points->getOrCreate($actor);
        $streak = (int) $row->checkin_streak;
        $last = $row->last_checkin_date;
        $doneToday = $last !== null && $last === DayBoundary::today();

        $consecutive = $last !== null && $last === DayBoundary::yesterday();
        $nextDay = $doneToday ? $streak : ($consecutive ? $streak + 1 : 1);

        return [
            'balance' => (int) $row->balance,
            'lifetime' => (int) $row->lifetime,
            'streak' => $streak,
            'doneToday' => $doneToday,
            'nextReward' => $doneToday ? 0 : $this->rewardForDay($nextDay),
            'makeupRemaining' => max(0, $this->makeupMax() - (int) $row->checkin_makeup_count),
            'canMakeup' => $this->canMakeup($row),
            'todayTotal' => (int) $this->db->table('point_system_checkin_days')
                ->where('date', DayBoundary::today())
                ->count(),
        ];
    }
}
