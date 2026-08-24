<?php

declare(strict_types=1);

use Flarum\Database\Migration;

return Migration::addColumns('point_system_user_points', [
    'checkin_streak' => ['integer', 'unsigned' => true, 'default' => 0, 'after' => 'lifetime'],
    // Plain DATE stored as 'Y-m-d' string — compared as a string against the
    // server-timezone day, so no timezone round-trip pitfalls on cast.
    'last_checkin_date' => ['date', 'nullable' => true, 'after' => 'checkin_streak'],
]);
