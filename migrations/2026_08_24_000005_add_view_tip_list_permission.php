<?php

use Flarum\Database\Migration;

return Migration::addPermissions([
    // Default to Moderator (group 4) only. Admin (group 1) is already covered
    // by the superuser grant, so listing it here too would render the admin
    // badge twice in the Permissions UI. Admins can grant it to other groups
    // from the Permissions page.
    'pointSystem.viewTipList' => [4],
]);
