<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

/**
 * Immutable metadata describing one decoration family.
 *
 * Centralizing this (instead of scattering `TYPE_*` constants, per-type
 * settings keys, model classes and API-resource classes across controllers,
 * the feature gate and extend.php) is what makes adding a 6th decoration type
 * a single self-contained registration rather than an edit in five places.
 *
 * See {@see DecorationRegistry}.
 */
final class DecorationType
{
    public function __construct(
        /** ShopClaim::TYPE_* / the `item_type` stored on claims. */
        public string $type,
        /** Admin setting that flips this family on/off. */
        public string $settingKey,
        /** Concrete model class for catalog items of this family. */
        public string $modelClass,
        /** JSON:API resource class backing the catalog endpoint. */
        public string $resourceClass,
        /** Optional per-row policy class (e.g. AvatarDecorationPolicy). */
        public ?string $policyClass = null,
    ) {}
}
