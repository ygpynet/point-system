<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

/**
 * Contract for a pluggable "point earner".
 *
 * A point earner is, at its core, an ordinary Flarum event listener: it
 * receives a domain event and credits points through {@see PointsRepository}.
 * Implementing this interface additionally lets the earner advertise *which*
 * event it listens to, so it can be registered declaratively through
 * {@see \Ramon\PointSystem\Extend\PointEarner} — including from OTHER
 * extensions that want to introduce a brand-new earning source without
 * touching this codebase.
 *
 * The actual point arithmetic stays in the listener's `handle()` (exactly as
 * before); this interface only adds the single static `event()` that the
 * extender needs to wire the listener into Flarum's dispatcher.
 */
interface PointEarner
{
    /**
     * Fully-qualified class name of the Flarum event this earner handles.
     *
     * Returned as a string literal so the class is never actually loaded at
     * extension-build time (the referenced event may belong to an optional
     * dependency such as `flarum/likes`).
     *
     * @return class-string
     */
    public static function event(): string;
}
