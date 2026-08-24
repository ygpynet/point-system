<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Model;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property int $sender_id
 * @property int $recipient_id
 * @property int $amount
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PostTip extends AbstractModel
{
    protected $table = 'point_system_post_tips';

    protected $casts = [
        'post_id' => 'integer',
        'sender_id' => 'integer',
        'recipient_id' => 'integer',
        'amount' => 'integer',
    ];

    protected $fillable = ['post_id', 'sender_id', 'recipient_id', 'amount'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
