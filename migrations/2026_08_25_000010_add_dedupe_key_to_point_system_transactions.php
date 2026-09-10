<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('point_system_transactions')) {
            $schema->table('point_system_transactions', function (Blueprint $table) use ($schema) {
                // Entity-scoped (idempotent) reasons get a deterministic key so
                // the DB can REJECT a duplicate credit even if the app-level
                // guard is bypassed by a queue retry / worker restart. NULL for
                // repeatable reasons (likes, tips) — they are intentionally not
                // de-duplicated.
                if (! $schema->hasColumn('point_system_transactions', 'dedupe_key')) {
                    $table->string('dedupe_key', 191)->nullable()->after('reason');
                    // Partial unique index: only non-null keys are constrained,
                    // so the many NULL rows never collide.
                    $table->unique('dedupe_key', 'point_system_tx_dedupe');
                }
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('point_system_transactions') && $schema->hasColumn('point_system_transactions', 'dedupe_key')) {
            $schema->table('point_system_transactions', function (Blueprint $table) use ($schema) {
                $table->dropUnique('point_system_tx_dedupe');
                $table->dropColumn('dedupe_key');
            });
        }
    },
];
