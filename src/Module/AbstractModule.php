<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

abstract class AbstractModule implements ModuleInterface
{
    public function enabled(): bool
    {
        return true;
    }

    /**
     * Modules this one requires to be present (and enabled) in the
     * composition root. extend.php fails fast when a dependency is missing.
     *
     * @return list<class-string<ModuleInterface>>
     */
    public function dependsOn(): array
    {
        return [];
    }
}
