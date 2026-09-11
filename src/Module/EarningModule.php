<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;
use Ramon\PointSystem\Extend\PointEarner;
use Ramon\PointSystem\Listener\AwardDiscussionPoints;
use Ramon\PointSystem\Listener\AwardPostPoints;
use Ramon\PointSystem\Listener\InitUserPoints;

/**
 * Core, always-on point earning: starting a discussion, posting a reply, and
 * registering an account. These are wired declaratively through the
 * {@see PointEarner} extender so the seam is identical for built-in and
 * third-party earners.
 */
class EarningModule extends AbstractModule
{
    public function extenders(): array
    {
        return [
            (new PointEarner())
                ->add(AwardDiscussionPoints::class)
                ->add(AwardPostPoints::class)
                ->add(InitUserPoints::class),
        ];
    }
}
