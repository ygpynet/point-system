<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Ramon\PointSystem\Support\DecorationRegistry;
use Ramon\PointSystem\Support\DecorationType as DecorationTypeDefinition;

/**
 * Declarative registration of a new decoration family, mirroring
 * {@see PointEarner} so third-party extensions can grow the catalog without
 * touching this codebase.
 *
 * Usage (in another extension's extend.php):
 *
 *     (new DecorationType())
 *         ->add('signature', 'acme.signature_enabled', SignatureModel::class, SignatureResource::class);
 *
 * The setting key must be registered by the owning extension (Flarum
 * Settings extender) so the admin panel can flip it; FeatureGate resolves
 * enablement through DecorationRegistry::settingFor(). Claim/equip endpoints
 * pick the family up automatically — they go through the registry, not a
 * hard-coded list.
 */
class DecorationType implements ExtenderInterface
{
    /** @var list<DecorationTypeDefinition> */
    private array $types = [];

    public function add(
        string $type,
        string $settingKey,
        string $modelClass,
        string $resourceClass,
        ?string $policyClass = null,
    ): self {
        $this->types[] = new DecorationTypeDefinition(
            $type,
            $settingKey,
            $modelClass,
            $resourceClass,
            $policyClass,
        );

        return $this;
    }

    public function extend(Container $container, Extension $extension = null): void
    {
        $registry = $container->make(DecorationRegistry::class);

        foreach ($this->types as $type) {
            $registry->register($type);
        }
    }
}
