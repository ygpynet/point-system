<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_checkin_days')) {
            $schema->create('point_system_checkin_days', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                // Plain 'Y-m-d' DATE, same DayBoundary convention as
                // point_system_user_points.last_checkin_date.
                $table->date('date');
                // 1-based position of this user among that day's check-ins.
                // Appended under the date-range lock taken by the controllers,
                // so concurrent check-ins can never draw the same number.
                $table->unsignedInteger('seq');

                $table->unique(['user_id', 'date']);
                $table->index(['date', 'seq']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('point_system_checkin_days');
    },
];
