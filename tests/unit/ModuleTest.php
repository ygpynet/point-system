<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use Flarum\Extend\ExtenderInterface;
use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Extend\PointEarner;
use Ramon\PointSystem\Module\ApiModule;
use Ramon\PointSystem\Module\EarningModule;
use Ramon\PointSystem\Module\LikesModule;
use Ramon\PointSystem\Module\NotificationModule;
use Ramon\PointSystem\Module\PermissionModule;
use Ramon\PointSystem\Module\SettingsModule;

/**
 * The modular composition root: every module returns ExtenderInterface
 * objects, and the earning module routes through the PointEarner seam.
 */
class ModuleTest extends TestCase
{
    public function test_cada_modulo_retorna_extenders(): void
    {
        $modules = [
            new EarningModule(),
            new LikesModule(),
            new NotificationModule(),
            new ApiModule(),
            new PermissionModule(),
            new SettingsModule(),
        ];

        foreach ($modules as $module) {
            $extenders = $module->extenders();
            $this->assertIsArray($extenders);
            $this->assertNotEmpty($extenders);
            foreach ($extenders as $extender) {
                $this->assertInstanceOf(ExtenderInterface::class, $extender);
            }
        }
    }

    public function test_earning_module_usa_o_extender_PointEarner(): void
    {
        $extenders = (new EarningModule())->extenders();

        $this->assertCount(1, $extenders);
        $this->assertInstanceOf(PointEarner::class, $extenders[0]);
    }

    public function test_PointEarner_rejeita_classe_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PointEarner())->add(\stdClass::class);
    }
}
