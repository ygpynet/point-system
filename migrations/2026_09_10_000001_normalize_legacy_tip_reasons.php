<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Builder;

/*
 * Normalizes LEGACY ledger rows that predate the reason-code contract:
 *
 *  - The old tip implementation wrote free-text reasons ("Received tip for
 *    post #66" / "Tipped post #66") instead of the `tip.in` / `tip.out`
 *    codes. The post number in the text always equals reference_id, so the
 *    mapping below loses no information.
 *  - The old tip implementation also stored the full PHP class name
 *    ("Flarum\Post\Post") as reference_type; every other writer uses the
 *    short `post` slug the frontend can localize.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_transactions')) {
            return;
        }

        $db = $schema->getConnection();

        $db->table('point_system_transactions')
            ->where('reason', 'like', 'Received tip for post #%')
            ->where('reference_type', 'Flarum\\Post\\Post')
            ->update(['reason' => 'tip.in', 'reference_type' => 'post']);

        $db->table('point_system_transactions')
            ->where('reason', 'like', 'Tipped post #%')
            ->where('reference_type', 'Flarum\\Post\\Post')
            ->update(['reason' => 'tip.out', 'reference_type' => 'post']);

        // Rows whose reason was already a code but that still carry the
        // legacy class-name reference type.
        $db->table('point_system_transactions')
            ->where('reference_type', 'Flarum\\Post\\Post')
            ->update(['reference_type' => 'post']);
    },
];
