<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;

/**
 * Client assets and public page routes for the forum and admin shells, plus
 * the locale loader.
 */
class FrontendModule implements ModuleInterface
{
    public function extenders(): array
    {
        return [
            (new Frontend('forum'))
                ->js(__DIR__.'/../../js/dist/forum.js')
                ->css(__DIR__.'/../../less/forum.less')
                ->route('/rewards', 'pointSystem.shop')
                ->route('/rewards/{tab}', 'pointSystem.shop.tab')
                ->route('/decorations', 'pointSystem.decorations')
                ->route('/decorations/{tab}', 'pointSystem.decorations.tab')
                ->route('/trades', 'pointSystem.trades'),

            (new Frontend('admin'))
                ->js(__DIR__.'/../../js/dist/admin.js')
                ->css(__DIR__.'/../../less/admin.less'),

            new Locales(__DIR__.'/../../locale'),
        ];
    }
}
