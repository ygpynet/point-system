<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Extend;

use Flarum\Extend\Event;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Ramon\PointSystem\Points\PointEarner as PointEarnerContract;
use Ramon\PointSystem\Points\PointEarnerRegistry;

/**
 * Declarative registration of a {@see PointEarner}.
 *
 * Usage (in extend.php or another extension's extend.php):
 *
 *     (new PointEarner())
 *         ->add(\Ramon\PointSystem\Listener\AwardDiscussionPoints::class)
 *         ->add(\Ramon\PointSystem\Listener\AwardPostPoints::class);
 *
 * Each earner is (a) recorded in the {@see PointEarnerRegistry} for
 * introspection and (b) wired into Flarum's event dispatcher as a normal
 * listener — so existing listeners keep working unchanged, while other
 * extensions gain a clean seam to add earning sources.
 */
class PointEarner implements ExtenderInterface
{
    /** @var array<int, class-string<PointEarnerContract>> */
    private array $earners = [];

    /**
     * @param class-string<PointEarnerContract> $earner
     */
    public function add(string $earner): self
    {
        if (! is_subclass_of($earner, PointEarnerContract::class)) {
            throw new \InvalidArgumentException(
                "$earner must implement " . PointEarnerContract::class
            );
        }

        $this->earners[] = $earner;

        return $this;
    }

    public function extend(Container $container, Extension $extension = null): void
    {
        $registry = $container->make(PointEarnerRegistry::class);

        $event = new Event();
        foreach ($this->earners as $earner) {
            $registry->register($earner);
            $event->listen($earner::event(), $earner);
        }

        $event->extend($container, $extension);
    }
}
