<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Model;

use Flarum\Database\AbstractModel;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $balance
 * @property int $lifetime
 * @property int|null $current_avatar_decoration_id
 * @property int|null $current_name_decoration_id
 * @property int|null $current_cover_decoration_id
 * @property int|null $current_title_decoration_id
 * @property int|null $current_post_hl_decoration_id
 * @property int $checkin_streak
 * @property int $checkin_makeup_count Make-ups used since the last real check-in.
 * @property string|null $last_checkin_date Plain 'Y-m-d' DATE — deliberately NOT cast,
 *                                          compared as a string against DayBoundary::today().
 * @property int $daily_earned Points credited since `daily_earned_date` (for the daily cap).
 * @property string|null $daily_earned_date Plain 'Y-m-d' DATE — not cast, compared as a string
 *                                         against DayBoundary::today(); null means "no day yet".
 */
class UserPoints extends AbstractModel
{
    use EventGeneratorTrait;

    protected $table = 'point_system_user_points';

    protected $casts = [
        'balance' => 'integer',
        'lifetime' => 'integer',
        'checkin_streak' => 'integer',
        'checkin_makeup_count' => 'integer',
        'daily_earned' => 'integer',
        'current_avatar_decoration_id' => 'integer',
        'current_name_decoration_id' => 'integer',
        'current_cover_decoration_id' => 'integer',
        'current_title_decoration_id' => 'integer',
        'current_post_hl_decoration_id' => 'integer',
    ];

    protected $fillable = [
        'user_id',
        'balance',
        'lifetime',
        'checkin_streak',
        'checkin_makeup_count',
        'last_checkin_date',
        'daily_earned',
        'daily_earned_date',
        'current_avatar_decoration_id',
        'current_name_decoration_id',
        'current_cover_decoration_id',
        'current_title_decoration_id',
        'current_post_hl_decoration_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatarDecoration(): BelongsTo
    {
        return $this->belongsTo(AvatarDecoration::class, 'current_avatar_decoration_id');
    }

    public function nameDecoration(): BelongsTo
    {
        return $this->belongsTo(NameDecoration::class, 'current_name_decoration_id');
    }

    public function coverDecoration(): BelongsTo
    {
        return $this->belongsTo(CoverDecoration::class, 'current_cover_decoration_id');
    }

    public function titleDecoration(): BelongsTo
    {
        return $this->belongsTo(TitleDecoration::class, 'current_title_decoration_id');
    }

    public function postHighlightDecoration(): BelongsTo
    {
        return $this->belongsTo(PostHighlightDecoration::class, 'current_post_hl_decoration_id');
    }
}
