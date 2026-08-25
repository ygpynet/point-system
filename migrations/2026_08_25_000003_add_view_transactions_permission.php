<?php

declare(strict_types=1);

use Flarum\Database\Migration;

return Migration::addPermissions([
    // Default to NO explicit group row. Administrators already hold every
    // permission through the superuser grant, so listing group 1 here would
    // render the admin badge twice in the Permissions UI (see the
    // pointSystem.viewTipList fix). Admins can grant it to other groups from
    // the Permissions page; everyone else is denied until then.
    'pointSystem.viewTransactions' => [],
]);
