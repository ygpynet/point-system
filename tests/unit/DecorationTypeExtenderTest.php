<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Extend\DecorationType;
use Ramon\PointSystem\Support\DecorationRegistry;

class DecorationTypeExtenderTest extends TestCase
{
    public function test_add_registers_type_into_registry(): void
    {
        $registry = DecorationRegistry::builtIn();

        $container = $this->createMock(Container::class);
        $container->method('make')
            ->with(DecorationRegistry::class)
            ->willReturn($registry);

        (new DecorationType())
            ->add('signature', 'acme.signature_enabled', \stdClass::class, \stdClass::class)
            ->extend($container);

        $this->assertTrue($registry->has('signature'));
        $this->assertSame('acme.signature_enabled', $registry->settingFor('signature'));
        $this->assertSame(\stdClass::class, $registry->modelFor('signature'));
        $this->assertSame(\stdClass::class, $registry->resourceFor('signature'));
    }

    public function test_add_with_policy_keeps_policy(): void
    {
        $registry = DecorationRegistry::builtIn();

        $container = $this->createMock(Container::class);
        $container->method('make')->willReturn($registry);

        (new DecorationType())
            ->add('badge', 'acme.badge_enabled', \stdClass::class, \stdClass::class, \stdClass::class)
            ->extend($container);

        $this->assertSame(\stdClass::class, $registry->get('badge')?->policyClass);
    }

    public function test_builtin_families_still_resolvable_after_third_party_add(): void
    {
        $registry = DecorationRegistry::builtIn();

        $container = $this->createMock(Container::class);
        $container->method('make')->willReturn($registry);

        (new DecorationType())
            ->add('signature', 'acme.signature_enabled', \stdClass::class, \stdClass::class)
            ->extend($container);

        $this->assertTrue($registry->has('avatar_decoration'));
        $this->assertTrue($registry->has('name_decoration'));
        $this->assertTrue($registry->has('cover_decoration'));
        $this->assertTrue($registry->has('title_decoration'));
        $this->assertTrue($registry->has('post_highlight_decoration'));
        $this->assertCount(6, $registry->types());
    }
}
