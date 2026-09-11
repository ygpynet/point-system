<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend\ApiResource;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Model;
use Flarum\Post\Post;
use Flarum\User\User;
use Ramon\PointSystem\Api\ForumAttributes;
use Ramon\PointSystem\Api\PostFields;
use Ramon\PointSystem\Api\Resource\AvatarDecorationResource;
use Ramon\PointSystem\Api\Resource\CoverDecorationResource;
use Ramon\PointSystem\Api\Resource\GroupOfferResource;
use Ramon\PointSystem\Api\Resource\NameDecorationResource;
use Ramon\PointSystem\Api\Resource\PostHighlightDecorationResource;
use Ramon\PointSystem\Api\Resource\ShopClaimResource;
use Ramon\PointSystem\Api\Resource\ShopItemResource;
use Ramon\PointSystem\Api\Resource\TitleDecorationResource;
use Ramon\PointSystem\Api\UserFields;
use Ramon\PointSystem\Model\PostTip;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Support\DecorationRegistry;

/**
 * The data surface: model relationships, every JSON:API resource (including
 * the decoration resources, generated from the {@see DecorationRegistry}), and
 * the user / post / forum field projections that expose points and claims to
 * clients.
 */
class ApiModule extends AbstractModule
{
    public function extenders(): array
    {
        $extenders = [
            (new Model(User::class))
                ->hasOne('pointsBalance', UserPoints::class, 'user_id'),

            (new Model(Post::class))
                ->hasMany('pointTipRecords', PostTip::class, 'post_id'),

            new ApiResource(ShopItemResource::class),
            new ApiResource(GroupOfferResource::class),
            new ApiResource(ShopClaimResource::class),

            (new ApiResource(UserResource::class))
                ->fields(UserFields::class)
                ->endpoint(
                    [Endpoint\Index::class, Endpoint\Show::class],
                    fn (Endpoint\Index|Endpoint\Show $endpoint) => $endpoint->eagerLoad('pointsBalance')
                ),

            (new ApiResource(PostResource::class))
                ->fields(PostFields::class)
                ->endpoint(
                    [Endpoint\Index::class, Endpoint\Show::class],
                    fn (Endpoint\Index|Endpoint\Show $endpoint) => $endpoint->eagerLoad('pointTipRecords.sender')
                ),

            (new ApiResource(ForumResource::class))
                ->fields(ForumAttributes::class),
        ];

        // One resource per registered decoration family. Adding a 6th family is
        // now a DecorationRegistry registration, not an edit to extend.php.
        foreach (DecorationRegistry::builtIn()->all() as $type) {
            $extenders[] = new ApiResource($type->resourceClass);
        }

        return $extenders;
    }
}
