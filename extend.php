<?php

/*
 * This file is part of ramon/point-system.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Ramon\PointSystem;

use Flarum\Extend;
use Ramon\PointSystem\Module\ApiModule;
use Ramon\PointSystem\Module\ApiRoutesModule;
use Ramon\PointSystem\Module\EarningModule;
use Ramon\PointSystem\Module\FrontendModule;
use Ramon\PointSystem\Module\LikesModule;
use Ramon\PointSystem\Module\NotificationModule;
use Ramon\PointSystem\Module\PermissionModule;
use Ramon\PointSystem\Module\SettingsModule;

// ── Composition root ─────────────────────────────────────────────────────────
// Each module owns a bounded area of behavior and returns the extenders that
// wire it up. Feature areas (earning, notifications, the API surface, the
// frontends, admin routes, permissions, settings) are independent and
// individually removable; see `src/Module/`.

$extenders = [
    (new Extend\ServiceProvider())
        ->register(PointSystemServiceProvider::class),
];

$modules = [
    new EarningModule(),
    new LikesModule(),
    new NotificationModule(),
    new ApiModule(),
    new FrontendModule(),
    new ApiRoutesModule(),
    new PermissionModule(),
    new SettingsModule(),
];

foreach ($modules as $module) {
    array_push($extenders, ...$module->extenders());
}

// ── GDPR (opcional) ──────────────────────────────────────────────────────────
// `class_exists` e não `ExtensionManager->isEnabled()`: o autoload do composer
// já está resolvido quando este arquivo é lido, o estado do gerenciador de
// extensões não necessariamente. flarum/gdpr fica em `suggest` — a extensão
// precisa bootar igual em fóruns que não o instalaram.
if (class_exists(\Flarum\Gdpr\Extend\UserData::class)) {
    $extenders[] = (new \Flarum\Gdpr\Extend\UserData())
        ->addType(Gdpr\PointSystemData::class);
}

return $extenders;
