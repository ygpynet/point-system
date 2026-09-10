<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

/**
 * Runtime registry of every point earner known to the system.
 *
 * Populated by {@see \Ramon\PointSystem\Extend\PointEarner} as extenders are
 * applied. Exposed as a singleton so admin tooling (and future UIs that let
 * admins toggle individual earning sources) can enumerate what feeds the
 * economy without hard-coding the list.
 *
 * @property array<int, class-string<PointEarner>> $earners
 */
final class PointEarnerRegistry
{
    /** @var array<int, class-string<PointEarner>> */
    private array $earners = [];

    public function register(string $earner): void
    {
        if (! is_subclass_of($earner, PointEarner::class)) {
            throw new \InvalidArgumentException("$earner must implement " . PointEarner::class);
        }

        if (! in_array($earner, $this->earners, true)) {
            $this->earners[] = $earner;
        }
    }

    /** @return array<int, class-string<PointEarner>> */
    public function all(): array
    {
        return $this->earners;
    }
}
