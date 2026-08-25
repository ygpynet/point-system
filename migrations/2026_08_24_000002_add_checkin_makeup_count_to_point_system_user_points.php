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
            if (! $schema->hasColumn('point_system_user_points', 'checkin_makeup_count')) {
                $table->integer('checkin_makeup_count')->unsigned()->default(0)->after('checkin_streak');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_user_points')) {
            return;
        }

        $schema->table('point_system_user_points', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('point_system_user_points', 'checkin_makeup_count')) {
                $table->dropColumn('checkin_makeup_count');
            }
        });
    },
];
