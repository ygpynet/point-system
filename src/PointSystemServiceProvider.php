<?php

/*
 * This file is part of ramon/point-system.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\PointSystem;

use Flarum\Foundation\AbstractServiceProvider;
use Ramon\PointSystem\Model\GroupOffer;
use Ramon\PointSystem\Points\PointEarnerRegistry;
use Ramon\PointSystem\Points\PointsRepositoryInterface;
use Ramon\PointSystem\Points\PostTipCounter;
use Ramon\PointSystem\Points\TipCounterInterface;
use Ramon\PointSystem\Points\TipRateLimiter;
use Ramon\PointSystem\Repository\PointsRepository;
use Ramon\PointSystem\Support\DecorationRegistry;
use Ramon\PointSystem\Support\PointReason;

class PointSystemServiceProvider extends AbstractServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->container->singleton(PointsRepository::class);
        // Bind the public contract to the concrete impl so third-party extensions
        // can type-hint PointsRepositoryInterface and stay decoupled.
        $this->container->singleton(PointsRepositoryInterface::class, fn ($c) => $c->make(PointsRepository::class));
        $this->container->singleton(PointEarnerRegistry::class);
        $this->container->singleton(DecorationRegistry::class, fn () => DecorationRegistry::builtIn());
        // Seeded once with the built-in reason codes; long-running workers reuse
        // the singleton instead of rebuilding on every request.
        $this->container->singleton(PointReason::class, fn () => PointReason::builtIn());
        // Tip abuse guard: bind the counter abstraction to the Eloquent-backed
        // implementation so TipRateLimiter stays unit-testable without a DB.
        $this->container->singleton(TipCounterInterface::class, PostTipCounter::class);
        $this->container->singleton(TipRateLimiter::class);
    }

    public function boot(): void
    {
        // PointsRepository é singleton e memoiza a lista de auto-offers em
        // memória. Em workers de longa vida (Octane / queue) o singleton
        // sobrevive entre requests, então uma edição de GroupOffer pelo admin
        // deixaria o cache obsoleto até o worker reciclar. Ligar a invalidação
        // aos eventos do model garante que QUALQUER create/update/delete de
        // GroupOffer — endpoint admin ou outro caminho — limpe o cache na hora.
        $invalidate = fn () => $this->container->make(PointsRepository::class)->clearAutoOffersCache();
        GroupOffer::saved($invalidate);
        GroupOffer::deleted($invalidate);
    }
}
