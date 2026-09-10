<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Ramon\PointSystem\Model\PointTransaction;

/**
 * Flattens a {@see PointTransaction} row (with its owning user eager-loaded)
 * into the shape the admin / profile "points ledger" UIs consume. The user
 * block mirrors what the trade serializer exposes so the frontends can render
 * an avatar + display name without a second round-trip.
 */
class TransactionSerializer
{
    public static function serialize(PointTransaction $tx): array
    {
        $user = $tx->user;

        return [
            'id' => $tx->id,
            'amount' => (int) $tx->amount,
            'reason' => $tx->reason,
            'referenceType' => $tx->reference_type,
            'referenceId' => $tx->reference_id,
            'createdAt' => $tx->created_at?->toIso8601String(),
            'user' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'displayName' => $user->display_name,
                'avatarUrl' => $user->avatar_url,
            ] : null,
        ];
    }
}
