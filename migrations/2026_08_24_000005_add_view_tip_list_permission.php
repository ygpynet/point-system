<?php

use Flarum\Database\Migration;

return Migration::addPermissions([
    // Group 1 = Admin, Group 4 = Moderator. By default only these can view the
    // tipper roster; admins can grant it to other groups from the Permissions page.
    'pointSystem.viewTipList' => [1, 4],
]);
