<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Model\ShopClaim;
use Ramon\PointSystem\Support\DecorationRegistry;
use Ramon\PointSystem\Support\DecorationType;

/**
 * Pure unit coverage for the decoration-family registry: the single source of
 * truth that replaced the scattered `TYPE_*` / settings-key lists.
 */
class DecorationRegistryTest extends TestCase
{
    public function test_builtIn_contem_as_cinco_familias_na_ordem(): void
    {
        $registry = DecorationRegistry::builtIn();

        $this->assertSame([
            ShopClaim::TYPE_AVATAR,
            ShopClaim::TYPE_NAME,
            ShopClaim::TYPE_COVER,
            ShopClaim::TYPE_TITLE,
            ShopClaim::TYPE_POST_HL,
        ], $registry->types());
    }

    public function test_avatar_tem_todas_as_chaves_resolvidas(): void
    {
        $registry = DecorationRegistry::builtIn();

        $this->assertTrue($registry->has(ShopClaim::TYPE_AVATAR));
        $this->assertSame('point-system.avatar_deco_enabled', $registry->settingFor(ShopClaim::TYPE_AVATAR));
        $this->assertSame(\Ramon\PointSystem\Model\AvatarDecoration::class, $registry->modelFor(ShopClaim::TYPE_AVATAR));
        $this->assertSame(\Ramon\PointSystem\Api\Resource\AvatarDecorationResource::class, $registry->resourceFor(ShopClaim::TYPE_AVATAR));
    }

    public function test_tipo_inexistente_retorna_null(): void
    {
        $registry = DecorationRegistry::builtIn();

        $this->assertFalse($registry->has('badge_decoration'));
        $this->assertNull($registry->settingFor('badge_decoration'));
        $this->assertNull($registry->resourceFor('badge_decoration'));
    }

    public function test_registro_novo_tipo_e_idempotente(): void
    {
        $registry = new DecorationRegistry();
        $type = new DecorationType('badge_decoration', 'point-system.badge_enabled', \stdClass::class, \stdClass::class);

        $registry->register($type);
        $registry->register($type); // duplicate must not duplicate

        $this->assertTrue($registry->has('badge_decoration'));
        $this->assertCount(1, $registry->all());
        $this->assertSame(\stdClass::class, $registry->modelFor('badge_decoration'));
    }
}
