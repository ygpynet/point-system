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
 * POST /api/point-system/checkin/makeup
 *
 * Paid make-up check-in (补签). Bridges ONE missed day per call: the day
 * immediately after the user's last checked day is retroactively marked as
 * checked, the streak advances by 1, and the configured point cost is
 * deducted from the BALANCE (lifetime untouched).
 *
 * Guards, all re-checked under lockForUpdate:
 *   - a real gap must exist (last_checkin_date < yesterday)
 *   - consecutive-makeup budget not exhausted (checkin_makeup_count < max);
 *     the counter resets on every REAL check-in
 *   - balance must cover the cost (deduct throws otherwise)
 *
 * Repeated calls walk the gap forward one day at a time until either the
 * budget runs out or the user is caught up to yesterday — after which today's
 * normal check-in continues the streak seamlessly.
 */
class MakeUpController implements RequestHandlerInterface
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

        if (! $this->settings->makeupEnabled()) {
            return new JsonResponse([
                'errors' => [['code' => 'feature_disabled', 'detail' => 'Make-up check-ins are disabled.']],
            ], 422);
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

                if ($row === null || $row->last_checkin_date === null) {
                    return ['code' => 'no_gap'];
                }

                // String compare on 'Y-m-d' is safe lexicographically.
                if ($row->last_checkin_date >= DayBoundary::yesterday()) {
                    return ['code' => 'no_gap'];
                }

                if ((int) $row->checkin_makeup_count >= $this->settings->makeupMax()) {
                    return ['code' => 'makeup_limit'];
                }

                // The filled day is the first hole right after the last
                // checked day; streak simply advances by one.
                $target = \Carbon\Carbon::createFromFormat('Y-m-d', $row->last_checkin_date)
                    ->addDay()
                    ->toDateString();

                // Deduct first: throws DomainException on insufficient
                // balance, aborting the outer transaction before any stamp.
                $this->points->deduct(
                    $actor,
                    $cost,
                    'user.checkin_makeup',
                    'user',
                    $actor->id,
                );

                $row->last_checkin_date = $target;
                $row->checkin_streak = (int) $row->checkin_streak + 1;
                $row->checkin_makeup_count = (int) $row->checkin_makeup_count + 1;
                $row->save();

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

                return [
                    'code' => 'ok',
                    'paid' => $cost,
                    'madeUpDate' => $target,
                ];
            });
        } catch (\DomainException $e) {
            return new JsonResponse([
                'errors' => [['code' => 'insufficient_balance', 'detail' => $e->getMessage()]],
            ], 422);
        }

        if ($outcome['code'] !== 'ok') {
            $messages = [
                'no_gap' => 'There is no missed day to make up.',
                'makeup_limit' => 'Consecutive make-up limit reached. Check in for real first.',
            ];

            return new JsonResponse([
                'errors' => [['code' => $outcome['code'], 'detail' => $messages[$outcome['code']]]],
            ], 422);
        }

        unset($outcome['code']);

        return new JsonResponse(['data' => $outcome + $this->settings->stateFor($this->points, $actor)], 200);
    }
}
