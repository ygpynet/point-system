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
use Ramon\PointSystem\Support\CheckInSettings;
use Ramon\PointSystem\Support\DayBoundary;

/**
 * POST /api/point-system/checkin
 *
 * Manual daily check-in. Reward GROWS with the streak and then caps out
 * (see {@see CheckInSettings::rewardForDay()}); a missed day breaks the
 * streak and the next real check-in starts over at day 1. Paid make-ups can
 * bridge a gap — see {@see MakeUpController}.
 */
class CheckInController implements RequestHandlerInterface
{
    public function __construct(
        protected PointsRepository $points,
        protected CheckInSettings $settings,
        protected ConnectionInterface $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $this->settings->enabled()) {
            return new JsonResponse([
                'errors' => [['code' => 'feature_disabled', 'detail' => 'Check-in rewards are disabled.']],
            ], 422);
        }

        $outcome = $this->db->transaction(function () use ($actor) {
            /*
             * Ensure the row exists, then re-read it under lock — the lock is
             * what serializes two racing requests; the loser sees the bumped
             * last_checkin_date.
             */
            $this->points->getOrCreate($actor);
            /** @var UserPoints|null $row */
            $row = UserPoints::query()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                return null;
            }

            $today = DayBoundary::today();

            if ($row->last_checkin_date !== null && $row->last_checkin_date === $today) {
                // Already checked in — report the rank recorded earlier today.
                $rank = $this->db->table('point_system_checkin_days')
                    ->where('user_id', $actor->id)
                    ->where('date', $today)
                    ->value('seq');

                return [
                    'alreadyCheckedIn' => true,
                    'awarded' => 0,
                    'day' => (int) $row->checkin_streak,
                    'streak' => (int) $row->checkin_streak,
                    'rank' => $rank !== null ? (int) $rank : null,
                ];
            }

            // Consecutive only when the very last checked day was yesterday.
            // Anything older breaks the streak — make-up check-ins exist to
            // bridge that gap before it happens.
            $isConsecutive = $row->last_checkin_date === DayBoundary::yesterday();
            $day = $isConsecutive ? ((int) $row->checkin_streak) + 1 : 1;
            $total = $this->settings->rewardForDay($day);

            /*
             * Draw today's check-in position. MAX(seq) ... FOR UPDATE takes a
             * gap/range lock on the date's index range, serialising concurrent
             * first-check-ins of the day so two users can never draw the same
             * number. Runs inside the same outer transaction as the stamp
             * below; any failure rolls everything back together.
             */
            $nextSeq = (int) $this->db->table('point_system_checkin_days')
                ->where('date', $today)
                ->lockForUpdate()
                ->max('seq') + 1;
            $this->db->table('point_system_checkin_days')->insert([
                'user_id' => $actor->id,
                'date' => $today,
                'seq' => $nextSeq,
            ]);

            // Stamp BEFORE award: if award() throws, the outer transaction
            // rolls the stamp back too. A real check-in always resets the
            // makeup counter.
            $row->last_checkin_date = $today;
            $row->checkin_streak = $day;
            $row->checkin_makeup_count = 0;
            $row->save();

            $this->points->award(
                $actor,
                $total,
                'user.check_in',
                'user',
                $actor->id,
                ['day' => $day],
            );

            return [
                'alreadyCheckedIn' => false,
                'awarded' => $total,
                'day' => $day,
                'streak' => $day,
                'rank' => $nextSeq,
            ];
        });

        if ($outcome === null) {
            return new JsonResponse(['errors' => [['code' => 'no_points_row', 'detail' => 'Points row missing.']]], 500);
        }

        return new JsonResponse(['data' => $outcome + $this->settings->stateFor($this->points, $actor)], 200);
    }
}
