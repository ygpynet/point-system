<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;
use Ramon\PointSystem\Support\ApiError;
use Ramon\PointSystem\Support\CheckInSettings;
use Ramon\PointSystem\Support\DayBoundary;

/**
 * POST /api/point-system/checkin/makeup
 *
 * Paid make-up check-in (补签). Bridges ONE missed day per call: the first
 * missing day of the user's trailing gap — the gap between the latest
 * checked day STRICTLY BEFORE today and yesterday. Deriving the gap from
 * the per-day record (instead of last_checkin_date) keeps the opportunity
 * alive even when a real check-in has already stamped TODAY: an accidental
 * tap must not erase the chance to repair the streak, and after the fill
 * the streak is recomputed as the full contiguous run ending at the latest
 * checked day.
 *
 * Guards, all re-checked under lockForUpdate:
 *   - a real trailing gap must exist (makeupTarget() != null)
 *   - consecutive-makeup budget not exhausted (checkin_makeup_count < max);
 *     the counter resets on every REAL check-in
 *   - balance must cover the cost (deduct throws otherwise)
 *
 * Repeated calls walk the gap forward one day at a time until either the
 * budget runs out or the user is caught up to yesterday.
 */
class MakeUpController implements RequestHandlerInterface
{
    public function __construct(
        protected PointsRepository $points,
        protected CheckInSettings $settings,
        protected ConnectionInterface $db,
        protected ApiError $errors,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $this->settings->makeupEnabled()) {
            return $this->errors->unprocessable('feature_disabled', 'feature_disabled');
        }

        $cost = $this->settings->makeupCost();

        try {
            $outcome = $this->db->transaction(function () use ($actor, $cost) {
                $this->points->getOrCreate($actor);
                /** @var UserPoints|null $row */
                $row = UserPoints::query()
                    ->where('user_id', $actor->id)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    return ['code' => 'no_gap'];
                }

                if ((int) $row->checkin_makeup_count >= $this->settings->makeupMax()) {
                    return ['code' => 'makeup_limit'];
                }

                // The gap is derived from the per-day record, NOT from
                // last_checkin_date: a real check-in performed while a gap
                // existed (the accidental tap) advances last_checkin_date to
                // today, and anchoring there would erase the gap forever and
                // leave the broken streak unrepairable.
                $target = $this->settings->makeupTarget((int) $actor->id);
                if ($target === null) {
                    return ['code' => 'no_gap'];
                }

                // Deduct first: throws DomainException on insufficient
                // balance, aborting the outer transaction before any stamp.
                $this->points->deduct(
                    $actor,
                    $cost,
                    'user.checkin_makeup',
                    'user',
                    $actor->id,
                );

                // Late arrivals append to that day's tail — the historical
                // order is already closed, so a make-up takes the next free
                // position rather than displacing anyone.
                $seq = (int) $this->db->table('point_system_checkin_days')
                    ->where('date', $target)
                    ->lockForUpdate()
                    ->max('seq') + 1;
                $this->db->table('point_system_checkin_days')->insert([
                    'user_id' => $actor->id,
                    'date' => $target,
                    'seq' => $seq,
                ]);

                // last_checkin_date advances to the latest checked day — it
                // may already BE today when the gap sits behind an accidental
                // check-in. The streak is RECOMPUTED as the contiguous run
                // ending at that day (AFTER the insert above, so the filled
                // day is part of the walk): the classic walk (+1) falls out
                // of the same formula, and the accidental case restores the
                // full bridged run instead of staying at 1.
                $lastChecked = max((string) $row->last_checkin_date, $target);
                $row->last_checkin_date = $lastChecked;
                $row->checkin_streak = $this->streakEndingAt((int) $actor->id, $lastChecked);
                $row->checkin_makeup_count = (int) $row->checkin_makeup_count + 1;
                $row->save();

                return [
                    'code' => 'ok',
                    'paid' => $cost,
                    'madeUpDate' => $target,
                ];
            });
        } catch (\DomainException $e) {
            return $this->errors->fromDomain($e);
        }

        if ($outcome['code'] !== 'ok') {
            $keys = [
                'no_gap' => 'makeup_no_gap',
                'makeup_limit' => 'makeup_limit',
            ];

            return $this->errors->unprocessable($outcome['code'], $keys[$outcome['code']]);
        }

        unset($outcome['code']);

        return new JsonResponse(['data' => $outcome + $this->settings->stateFor($this->points, $actor)], 200);
    }

    /**
     * Length of the user's contiguous checked run ending at $endDate,
     * computed from the per-day record. Used instead of the old
     * `streak + 1` because a make-up can fill a hole BEHIND an existing
     * check-in (the accidental-tap case), which extends the run ending at
     * the LATEST checked day rather than appending to a stale counter.
     */
    private function streakEndingAt(int $userId, string $endDate): int
    {
        $dates = $this->db->table('point_system_checkin_days')
            ->where('user_id', $userId)
            ->where('date', '<=', $endDate)
            ->orderByDesc('date')
            ->pluck('date');

        $streak = 0;
        $cursor = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate, DayBoundary::timezone())
            ->startOfDay();

        foreach ($dates as $date) {
            if ((string) $date !== $cursor->toDateString()) {
                break;
            }
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }
}
