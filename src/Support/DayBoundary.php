<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Carbon\Carbon;

/**
 * The "calendar day" used by the check-in feature, following the SERVER's
 * own timezone. Flarum pins the runtime zone to UTC at boot
 * (Foundation\Site.php), hiding whatever the host was configured with — but
 * the original value survives in the INI (verified: ini_get keeps reporting
 * it after the override). Prefer it; fall back to UTC only when date.timezone
 * is empty.
 *
 * With phpstudy's default `date.timezone = PRC`, the day rolls over at
 * Beijing midnight: a claim made at 23:59 can repeat right after 00:00.
 *
 * Days are handled as plain 'Y-m-d' STRINGS (never Carbon casts) so that a
 * DATE column round-trips without timezone reinterpretation — string equality
 * against {@see today()} is the single source of truth for "same day".
 */
final class DayBoundary
{
    public static function timezone(): string
    {
        return ini_get('date.timezone') ?: 'UTC';
    }

    public static function today(): string
    {
        return Carbon::now(self::timezone())->toDateString();
    }

    public static function yesterday(): string
    {
        return Carbon::now(self::timezone())->subDay()->toDateString();
    }
}
