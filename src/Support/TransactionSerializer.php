<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Support\Collection;
use Ramon\PointSystem\Model\PointTransaction;

/**
 * Flattens a {@see PointTransaction} row (with its owning user eager-loaded)
 * into the shape the admin / profile "points ledger" UIs consume. The user
 * block mirrors what the trade serializer exposes so the frontends can render
 * an avatar + display name without a second round-trip.
 */
class TransactionSerializer
{
    public static function serialize(PointTransaction $tx, ?string $referenceUrl = null): array
    {
        $user = $tx->user;

        return [
            'id' => $tx->id,
            'amount' => (int) $tx->amount,
            'reason' => $tx->reason,
            'referenceType' => $tx->reference_type,
            'referenceId' => $tx->reference_id,
            // Deep link to the referenced object (post permalink, discussion,
            // user profile) — null when the row is gone or the type has no
            // routable page. The frontend renders the reference as a link
            // only when this is set.
            'referenceUrl' => $referenceUrl,
            'createdAt' => $tx->created_at?->toIso8601String(),
            'user' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'displayName' => $user->display_name,
                'avatarUrl' => $user->avatar_url,
            ] : null,
        ];
    }

    /**
     * Deep links for a PAGE of transactions, keyed by "{type}:{id}".
     *
     * Two batched lookups (posts, users) per page — discussion links need
     * only the id. Deleted posts simply produce no entry, which the frontend
     * renders as plain text instead of a link.
     */
    public static function referenceUrls(Collection $rows, UrlGenerator $urls): array
    {
        $out = [];
        $route = fn (string $name, array $params): string => $urls->to('forum')->route($name, $params);

        $postIds = $rows->filter(fn ($t) => $t->reference_type === 'post')
            ->pluck('reference_id')->map(fn ($v) => (int) $v)->unique()->filter()->values();
        if ($postIds->isNotEmpty()) {
            Post::query()->whereIn('id', $postIds)->get(['id', 'discussion_id', 'number'])
                ->each(function (Post $p) use (&$out, $route) {
                    $out['post:'.$p->id] = $route('discussion', [
                        'id' => (int) $p->discussion_id,
                        'near' => $p->number ?? 1,
                    ]);
                });
        }

        $rows->filter(fn ($t) => $t->reference_type === 'discussion')
            ->pluck('reference_id')->map(fn ($v) => (int) $v)->unique()->filter()
            ->each(fn (int $d) => $out['discussion:'.$d] = $route('discussion', ['id' => $d]));

        $userIds = $rows->filter(fn ($t) => $t->reference_type === 'user')
            ->pluck('reference_id')->map(fn ($v) => (int) $v)->unique()->filter()->values();
        if ($userIds->isNotEmpty()) {
            User::query()->whereIn('id', $userIds)->get(['id', 'username'])
                ->each(function (User $u) use (&$out, $route) {
                    $out['user:'.$u->id] = $route('user', ['username' => $u->username]);
                });
        }

        return $out;
    }
}
