<?php

declare(strict_types=1);

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Seeds `pointSystem.viewPool` — the make-it-manageable permission for the
 * points-pool widget. Before this row existed the pool stat (issued total +
 * remaining budget) was serialized to EVERY visitor unconditionally, with no
 * admin control anywhere in the panel.
 *
 * Defaults to Members only: registered users keep seeing the widget, while
 * logged-out visitors lose it (the pool is community meta-info, not landing-
 * page bait). Administrators hold every permission implicitly and are
 * deliberately NOT listed — a group-1 row renders the admin badge twice in
 * the Permissions UI (see 2026_08_24_000005). Operators can widen or narrow
 * the grant per group — custom groups included — from the Permissions page.
 *
 * Why the closure type-hints `Builder $schema`: Flarum 2's
 * `Migrator::runClosureMigration` always passes a SchemaBuilder.
 *
 * Idempotent: skips each insert when the row already exists; `down` removes
 * every assignment so uninstalling leaves no dangling permission rows.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        foreach ([Group::MEMBER_ID] as $groupId) {
            $exists = $db->table('group_permission')
                ->where('group_id', $groupId)
                ->where('permission', 'pointSystem.viewPool')
                ->exists();
            if (! $exists) {
                $db->table('group_permission')->insert([
                    'group_id'   => $groupId,
                    'permission' => 'pointSystem.viewPool',
                ]);
            }
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'pointSystem.viewPool')
            ->delete();
    },
];
