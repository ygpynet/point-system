<?php

declare(strict_types=1);

use Flarum\Database\Migration;

return Migration::addColumns('point_system_user_points', [
    // Make-up check-ins used since the last REAL check-in. Reset to 0 by a
    // real check-in; the makeup endpoint refuses to go past the configured
    // max consecutive makeups.
    'checkin_makeup_count' => ['integer', 'unsigned' => true, 'default' => 0, 'after' => 'checkin_streak'],
]);
