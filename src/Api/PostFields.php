<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;

/**
 * Post API fields related to tipping.
 *
 *  - `pointSystemTipsTotal` (always visible): the grand total of all tips a
 *    post has received. Shown to everyone in the action bar so readers can see
 *    a post was rewarded.
 *
 *  - `pointSystemTips` (restricted): the per-sender breakdown — who tipped and
 *    how much. Gated behind the `pointSystem.viewTipList` permission so the
 *    roster of tippers is only exposed to users/groups allowed to view it.
 *
 * The underlying `pointTipRecords` relation (declared on Post in extend.php)
 * is eager-loaded with its `sender`; the aggregation is done in PHP against the
 * already-loaded collection so a discussion page costs a single extra query.
 */
class PostFields
{
    public function __invoke(): array
    {
        return [
            Schema\Integer::make('pointSystemTipsTotal')
                ->get(function (Post $post): int {
                    $tips = $post->pointTipRecords;

                    if ($tips === null || $tips->isEmpty()) {
                        return 0;
                    }

                    return (int) $tips->sum('amount');
                }),

            Schema\Arr::make('pointSystemTips')
                ->visible(function (Post $post, Context $context) {
                    return $context->getActor()->can('pointSystem.viewTipList');
                })
                ->get(function (Post $post): array {
                    $tips = $post->pointTipRecords;

                    if ($tips === null || $tips->isEmpty()) {
                        return [];
                    }

                    return $tips
                        ->groupBy('sender_id')
                        ->map(function ($rows) {
                            $first = $rows->first();

                    return [
                        'senderId' => (int) $first->sender_id,
                        'senderName' => $first->sender?->display_name ?? '',
                        // Expose the sender's username (slug) and avatar URL so the
                        // forum modal can render an avatar + profile link WITHOUT
                        // relying on the user already being loaded into the Flarum
                        // store (which it often isn't for tippers not on this page).
                        'senderUsername' => $first->sender?->username ?? '',
                        'senderAvatarUrl' => $first->sender?->avatar_url ?? null,
                        'amount' => (int) $rows->sum('amount'),
                    ];
                        })
                        ->sortByDesc('amount')
                        ->values()
                        ->toArray();
                }),
        ];
    }
}
