<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Ramon\PointSystem\Access\AvatarDecorationPolicy;
use Ramon\PointSystem\Api\Resource\AvatarDecorationResource;
use Ramon\PointSystem\Api\Resource\CoverDecorationResource;
use Ramon\PointSystem\Api\Resource\NameDecorationResource;
use Ramon\PointSystem\Api\Resource\PostHighlightDecorationResource;
use Ramon\PointSystem\Api\Resource\TitleDecorationResource;
use Ramon\PointSystem\Model\AvatarDecoration;
use Ramon\PointSystem\Model\CoverDecoration;
use Ramon\PointSystem\Model\NameDecoration;
use Ramon\PointSystem\Model\PostHighlightDecoration;
use Ramon\PointSystem\Model\ShopClaim;
use Ramon\PointSystem\Model\TitleDecoration;

/**
 * Single source of truth for the decoration families supported by the
 * extension.
 *
 * The five built-in families are registered through {@see builtIn()}; future
 * families (signatures, banners, badges…) register themselves the same way.
 * Everything that currently branches on a decoration `type` — the feature
 * gate, the claim controller's allow-list, the API-resource wiring in
 * extend.php — goes through this registry instead of a hard-coded constant
 * list, so a new type is added in exactly one spot.
 *
 * @property array<string, DecorationType> $types
 */
final class DecorationRegistry
{
    /** @var array<string, DecorationType> */
    private array $types = [];

    public function register(DecorationType $type): void
    {
        $this->types[$type->type] = $type;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): ?DecorationType
    {
        return $this->types[$type] ?? null;
    }

    public function settingFor(string $type): ?string
    {
        return $this->get($type)?->settingKey;
    }

    public function modelFor(string $type): ?string
    {
        return $this->get($type)?->modelClass;
    }

    public function resourceFor(string $type): ?string
    {
        return $this->get($type)?->resourceClass;
    }

    /** @return string[] */
    public function types(): array
    {
        return array_keys($this->types);
    }

    /** @return DecorationType[] */
    public function all(): array
    {
        return array_values($this->types);
    }

    /**
     * The five shipped decoration families, in catalog display order.
     *
     * Used both as the container singleton (via the service provider) and, at
     * extension-build time, to generate the API-resource extenders — so the
     * two views of the registry can never drift.
     */
    public static function builtIn(): self
    {
        $registry = new self();

        $registry->register(new DecorationType(
            ShopClaim::TYPE_AVATAR,
            'point-system.avatar_deco_enabled',
            AvatarDecoration::class,
            AvatarDecorationResource::class,
            AvatarDecorationPolicy::class,
        ));
        $registry->register(new DecorationType(
            ShopClaim::TYPE_NAME,
            'point-system.name_deco_enabled',
            NameDecoration::class,
            NameDecorationResource::class,
        ));
        $registry->register(new DecorationType(
            ShopClaim::TYPE_COVER,
            'point-system.cover_deco_enabled',
            CoverDecoration::class,
            CoverDecorationResource::class,
        ));
        $registry->register(new DecorationType(
            ShopClaim::TYPE_TITLE,
            'point-system.title_deco_enabled',
            TitleDecoration::class,
            TitleDecorationResource::class,
        ));
        $registry->register(new DecorationType(
            ShopClaim::TYPE_POST_HL,
            'point-system.post_hl_deco_enabled',
            PostHighlightDecoration::class,
            PostHighlightDecorationResource::class,
        ));

        return $registry;
    }
}
