<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_claims')) {
            return;
        }

        $schema->table('point_system_claims', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('point_system_claims', 'quantity')) {
                $table->integer('quantity')->unsigned()->default(1)->after('item_id');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('point_system_claims')) {
            return;
        }

        $schema->table('point_system_claims', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('point_system_claims', 'quantity')) {
                $table->dropColumn('quantity');
            }
        });
    },
];
