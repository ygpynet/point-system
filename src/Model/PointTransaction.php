<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array|null $meta
 * @property \Carbon\Carbon $created_at
 */
class PointTransaction extends AbstractModel
{
    protected $table = 'point_system_transactions';

    public $timestamps = false;

    protected $casts = [
        'amount' => 'integer',
        'reference_id' => 'integer',
        'created_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $fillable = ['user_id', 'amount', 'reason', 'reference_type', 'reference_id', 'meta', 'dedupe_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Emit a global domain event the moment a ledger row is persisted, so other
     * extensions can react to ANY point movement (earn / spend / revert / tip)
     * without hooking each call site. Decoupled from the UserPoints event
     * generator: this fires once per written row regardless of who credited it.
     */
    protected static function booted(): void
    {
        static::created(function (PointTransaction $tx) {
            try {
                $dispatcher = app(\Illuminate\Contracts\Events\Dispatcher::class);
                $dispatcher->dispatch(new \Ramon\PointSystem\Event\PointsTransactionRecorded($tx));
            } catch (\Throwable) {
                // Never let observability break a financial write.
            }
        });
    }
}
