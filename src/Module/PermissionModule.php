<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Policy;
use Ramon\PointSystem\Access\AvatarDecorationPolicy;
use Ramon\PointSystem\Access\ShopItemPolicy;
use Ramon\PointSystem\Model\AvatarDecoration;

/**
 * Authorization: the per-row policy for avatar decorations and the global
 * policy that all shop-family actions funnel through.
 */
class PermissionModule extends AbstractModule
{
    public function extenders(): array
    {
        return [
            (new Policy())
                ->modelPolicy(AvatarDecoration::class, AvatarDecorationPolicy::class)
                ->globalPolicy(ShopItemPolicy::class),
        ];
    }
}
