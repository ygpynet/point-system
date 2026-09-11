<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Carbon\Carbon;
use Flarum\Http\ActorReference;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Ramon\PointSystem\Controller\MakeUpController;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;
use Ramon\PointSystem\Support\ApiError;
use Ramon\PointSystem\Support\CheckInSettings;
use Ramon\PointSystem\Support\DayBoundary;

/**
 * Make-up check-in repair flow (debug session 2026-09-11).
 *
 * Product rule under test: a user who is make-up eligible must NOT lose the
 * opportunity by accidentally tapping the regular check-in button. Before the
 * fix the real check-in advanced last_checkin_date to TODAY, the trailing gap
 * was erased (make-up answered no_gap forever) and the broken streak became
 * unrepairable.
 *
 * The fix derives the gap from point_system_checkin_days (the authoritative
 * per-day record) instead of last_checkin_date, and recomputes the streak as
 * the contiguous run ending at the latest checked day after each fill.
 */
class MakeUpRepairTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UserPoints::create(['user_id' => 1, 'balance' => 100, 'lifetime' => 100]);
    }

    private function day(int $offset): string
    {
        return Carbon::createFromFormat('Y-m-d', DayBoundary::today(), DayBoundary::timezone())
            ->startOfDay()
            ->addDays($offset)
            ->toDateString();
    }

    private function checkin(int $userId, string $date, int $seq = 1): void
    {
        $this->connection()->table('point_system_checkin_days')->insert([
            'user_id' => $userId,
            'date' => $date,
            'seq' => $seq,
        ]);
    }

    private function seedRun(int $userId, int $fromOffset, int $toOffset, int $streak): void
    {
        $seq = 1;
        for ($o = $fromOffset; $o <= $toOffset; $o++) {
            $this->checkin($userId, $this->day($o), $seq++);
        }
    }

    private function controller(): MakeUpController
    {
        $translator = new class implements \Flarum\Locale\TranslatorInterface {
            public function trans($id, array $parameters = [], $domain = null, $locale = null): string
            {
                return (string) $id;
            }
            public function get($key, array $replace = [], $locale = null): string
            {
                return (string) $key;
            }
            public function choice($key, $number, array $replace = [], $locale = null): string
            {
                return (string) $key;
            }
            public function getLocale(): string
            {
                return 'en';
            }
            public function setLocale($locale) {}
        };

        return new MakeUpController(
            $this->makePointsRepository(),
            new CheckInSettings($this->defaultSettings(), $this->connection()),
            $this->connection(),
            new ApiError($translator),
        );
    }

    private function makeup(int $userId): array
    {
        $user = new User();
        $user->id = $userId;

        $ref = new ActorReference();
        $ref->setActor($user);

        $request = (new ServerRequest())->withAttribute('actorReference', $ref);
        $response = $this->controller()->handle($request);

        return json_decode((string) $response->getBody(), true);
    }

    private function points(int $userId): UserPoints
    {
        return UserPoints::query()->where('user_id', $userId)->first();
    }

    /**
     * Equivalence regression: the classic flow (gap = yesterday, today NOT
     * checked) must behave exactly as before — fill the day after the last
     * checked day, streak advances by one.
     */
    public function test_classic_gap_fill_is_unchanged(): void
    {
        $this->seedRun(1, -6, -2, 5);
        $row = $this->points(1);
        $row->last_checkin_date = $this->day(-2);
        $row->checkin_streak = 5;
        $row->save();

        $out = $this->makeup(1);

        $this->assertSame($this->day(-1), $out['data']['madeUpDate']);
        $this->assertSame(6, (int) $this->points(1)->checkin_streak);
        $this->assertSame($this->day(-1), (string) $this->points(1)->last_checkin_date);
        $this->assertSame(90, (int) $this->points(1)->balance);
    }

    /**
     * THE FIX: the user had a trailing gap, accidentally performed the REAL
     * check-in for today, and must still be able to repair — the make-up
     * fills the erased gap and the streak is recomputed as the full
     * contiguous run ending at today.
     */
    public function test_accidental_checkin_does_not_destroy_the_makeup_opportunity(): void
    {
        // Run ended the day before yesterday; yesterday was missed.
        $this->seedRun(1, -6, -2, 5);
        // Then the user accidentally checks in for TODAY (streak resets to 1).
        $this->checkin(1, $this->day(0));
        $row = $this->points(1);
        $row->last_checkin_date = $this->day(0);
        $row->checkin_streak = 1;
        $row->checkin_makeup_count = 0;
        $row->save();

        $out = $this->makeup(1);

        $this->assertSame($this->day(-1), $out['data']['madeUpDate'], json_encode($out) ?: '');
        $this->assertSame($this->day(-1), $out['data']['madeUpDate'], 'the missed day (not some ancient hole) must be filled');

        $row = $this->points(1);
        $this->assertSame(7, (int) $row->checkin_streak, 'run D-6..D-0 (7 days) is contiguous again');
        $this->assertSame($this->day(0), (string) $row->last_checkin_date, 'last checked day remains today');
        $this->assertSame(1, (int) $row->checkin_makeup_count);
        $this->assertSame(90, (int) $row->balance);
        $this->assertSame(1, PointTransaction::query()->where('reason', 'user.checkin_makeup')->count());
    }

    /**
     * Multi-day gap after an accidental check-in: fills walk forward from
     * the anchor, and the streak only reflects the contiguous run ending at
     * the latest checked day.
     */
    public function test_multi_day_gap_after_accidental_checkin_walks_forward(): void
    {
        $this->seedRun(1, -4, -3, 2);
        $this->checkin(1, $this->day(0));
        $row = $this->points(1);
        $row->last_checkin_date = $this->day(0);
        $row->checkin_streak = 1;
        $row->save();

        $first = $this->makeup(1);
        $this->assertSame($this->day(-2), $first['data']['madeUpDate'], json_encode($first) ?: '');
        $this->assertSame($this->day(-2), $first['data']['madeUpDate']);
        // Run ending today is still just [today] — D-1 is still missing.
        $this->assertSame(1, (int) $this->points(1)->checkin_streak);

        $second = $this->makeup(1);
        $this->assertSame($this->day(-1), $second['data']['madeUpDate'], json_encode($second) ?: '');
        $this->assertSame($this->day(-1), $second['data']['madeUpDate']);
        $this->assertSame(5, (int) $this->points(1)->checkin_streak, 'D-4..D-0 fully contiguous → streak 5');
        $this->assertSame($this->day(0), (string) $this->points(1)->last_checkin_date);
    }

    /**
     * Preserved guard: a user fully caught up through yesterday has no gap.
     */
    public function test_no_gap_when_caught_up_through_yesterday(): void
    {
        $this->seedRun(1, -3, -1, 3);
        $row = $this->points(1);
        $row->last_checkin_date = $this->day(-1);
        $row->checkin_streak = 3;
        $row->save();

        $out = $this->makeup(1);

        $this->assertSame('no_gap', $out['errors'][0]['code'] ?? null, json_encode($out));
        $this->assertSame(100, (int) $this->points(1)->balance, 'nothing deducted');
    }

    /**
     * The widget gate keeps the "whole gap must be bridgeable" rule — now
     * computed from the checkin_days anchor instead of last_checkin_date,
     * so the button stays available after an accidental check-in.
     */
    public function test_can_makeup_uses_the_trailing_gap_and_budget(): void
    {
        $settings = new CheckInSettings($this->defaultSettings(), $this->connection());

        // Run ended D-2, accidental check-in today → gap D-1 only.
        $this->seedRun(1, -6, -2, 5);
        $this->checkin(1, $this->day(0));
        $row = $this->points(1);
        $row->last_checkin_date = $this->day(0);
        $row->checkin_streak = 1;
        $row->save();

        $this->assertTrue($settings->canMakeup($row), 'accidental check-in must keep the opportunity available');

        // Two-day gap (D-2 and D-1 both missing) with only 1 budget slot left.
        $this->seedRun(2, -5, -3, 3);
        $this->checkin(2, $this->day(0));
        UserPoints::create(['user_id' => 2, 'balance' => 100, 'lifetime' => 100]);
        $row2 = $this->points(2);
        $row2->last_checkin_date = $this->day(0);
        $row2->checkin_makeup_count = 2;
        $row2->save();

        $this->assertFalse($settings->canMakeup($row2), 'partial fills buy nothing — the widget must not offer them');
    }
}
