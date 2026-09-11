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
class LikesModule extends AbstractModule
{
    /**
     * The Conditional extender already makes this module inert when
     * flarum/likes is absent or disabled; declaring it here too keeps the
     * composition root honest — extend.php filters disabled modules before
     * calling extenders(), so the module never even registers a no-op.
     */
    #[\Override]
    public function enabled(): bool
    {
        return class_exists(\Flarum\Likes\Event\PostWasLiked::class);
    }

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
