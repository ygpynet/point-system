<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;

/**
 * One cohesive slice of the extension's behavior.
 *
 * Each module owns a bounded area (earning, notifications, the API surface,
 * the frontends, the admin routes, permissions, settings…) and exposes the
 * Flarum {@see ExtenderInterface} objects that wire it up. `extend.php` becomes
 * a thin composition root: instantiate the modules and flatten their
 * extenders. Adding or removing a feature is now a one-line change to that
 * list, and each module is independently testable and readable.
 */
interface ModuleInterface
{
    /** @return ExtenderInterface[] */
    public function extenders(): array;
}
