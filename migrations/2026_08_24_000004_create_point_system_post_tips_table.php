<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tracks every "tip" a user sends to a post author. One row per tip
 * (a user can tip the same post many times; the post display aggregates
 * by sender). Used both for the audit trail AND the per-post "who tipped
 * how much" widget rendered under each post.
 *
 *   point_system_post_tips — one row per tip transfer.
 *
 * Columns:
 *   post_id      — the tipped post
 *   sender_id    — the user who paid the points
 *   recipient_id — the post author who received them
 *   amount       — points moved (always positive here)
 *
 * Indexes:
 *   - (post_id) for the per-post aggregate query on every post render
 *   - (sender_id) / (recipient_id) for any future "tips given/received"
 *     profile views
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_post_tips')) {
            $schema->create('point_system_post_tips', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('post_id');
                $t->unsignedInteger('sender_id');
                $t->unsignedInteger('recipient_id');
                $t->unsignedInteger('amount')->default(0);
                $t->timestamps();

                $t->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
                $t->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
                $t->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();

                $t->index(['post_id'], 'post_tips_post_idx');
                $t->index(['sender_id'], 'post_tips_sender_idx');
                $t->index(['recipient_id'], 'post_tips_recipient_idx');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('point_system_post_tips');
    },
];
