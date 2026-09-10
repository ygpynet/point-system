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
            if (! $schema->hasColumn('point_system_user_points', 'checkin_streak')) {
                $table->integer('checkin_streak')->unsigned()->default(0)->after('lifetime');
            }
            if (! $schema->hasColumn('point_system_user_points', 'last_checkin_date')) {
                $table->date('last_checkin_date')->nullable()->after('checkin_streak');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_user_points')) {
            return;
        }

        $schema->table('point_system_user_points', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('point_system_user_points', 'checkin_streak')) {
                $table->dropColumn('checkin_streak');
            }
            if ($schema->hasColumn('point_system_user_points', 'last_checkin_date')) {
                $table->dropColumn('last_checkin_date');
            }
        });
    },
];
