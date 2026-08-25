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
            if (! $schema->hasColumn('point_system_user_points', 'daily_earned')) {
                $table->integer('daily_earned')->unsigned()->default(0)->after('checkin_makeup_count');
            }
            if (! $schema->hasColumn('point_system_user_points', 'daily_earned_date')) {
                $table->date('daily_earned_date')->nullable()->after('daily_earned');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_user_points')) {
            return;
        }

        $schema->table('point_system_user_points', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('point_system_user_points', 'daily_earned_date')) {
                $table->dropColumn('daily_earned_date');
            }
            if ($schema->hasColumn('point_system_user_points', 'daily_earned')) {
                $table->dropColumn('daily_earned');
            }
        });
    },
];
