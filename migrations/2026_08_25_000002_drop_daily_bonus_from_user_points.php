<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_user_points')) {
            return;
        }

        $schema->table('point_system_user_points', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('point_system_user_points', 'last_daily_bonus_at')) {
                $table->dropColumn('last_daily_bonus_at');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_user_points')) {
            return;
        }

        $schema->table('point_system_user_points', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('point_system_user_points', 'last_daily_bonus_at')) {
                $table->timestamp('last_daily_bonus_at')->nullable();
            }
        });
    },
];
