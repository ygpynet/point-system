<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\Conditional;
use Flarum\Extend\ExtenderInterface;
use Ramon\PointSystem\Extend\PointEarner;
use Ramon\PointSystem\Listener\AwardLikePoints;
use Ramon\PointSystem\Listener\RevertLikePoints;

/**
 * Optional earning tied to `flarum/likes`: points for a like received and a
 * like given, plus reverting them on unlike. Gated on the extension being
 * enabled, exactly as before.
 */
class LikesModule implements ModuleInterface
{
    public function extenders(): array
    {
        return [
            (new Conditional())
                ->whenExtensionEnabled('flarum-likes', fn () => [
                    (new PointEarner())
                        ->add(AwardLikePoints::class)
                        ->add(RevertLikePoints::class),
                ]),
        ];
    }
}
