<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\Event;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Notification;
use Ramon\PointSystem\Event\ItemGranted;
use Ramon\PointSystem\Event\PointsManuallyChanged;
use Ramon\PointSystem\Event\PostTipped;
use Ramon\PointSystem\Event\TierClaimed;
use Ramon\PointSystem\Event\TradeAccepted;
use Ramon\PointSystem\Event\TradeCompleted;
use Ramon\PointSystem\Event\TradeRequested;
use Ramon\PointSystem\Listener\SendNotificationWhenItemGranted;
use Ramon\PointSystem\Listener\SendNotificationWhenPointsChanged;
use Ramon\PointSystem\Listener\SendNotificationWhenPostTipped;
use Ramon\PointSystem\Listener\SendNotificationWhenTierClaimed;
use Ramon\PointSystem\Listener\SendNotificationWhenTradeAccepted;
use Ramon\PointSystem\Listener\SendNotificationWhenTradeCompleted;
use Ramon\PointSystem\Listener\SendNotificationWhenTradeRequested;
use Ramon\PointSystem\Notification\ItemGrantedBlueprint;
use Ramon\PointSystem\Notification\PointsManualBlueprint;
use Ramon\PointSystem\Notification\PostTippedBlueprint;
use Ramon\PointSystem\Notification\TierClaimedBlueprint;
use Ramon\PointSystem\Notification\TradeAcceptedBlueprint;
use Ramon\PointSystem\Notification\TradeCompletedBlueprint;
use Ramon\PointSystem\Notification\TradeRequestedBlueprint;

/**
 * Notification fan-out. Every meaningful state change raises a domain event;
 * this module binds those events to their blueprints and dispatchers so the
 * alert pipeline (and `flarum/realtime`, when present) stays in sync with the
 * rest of the system.
 */
class NotificationModule extends AbstractModule
{
    public function extenders(): array
    {
        return [
            (new Event())
                ->listen(PointsManuallyChanged::class, SendNotificationWhenPointsChanged::class)
                ->listen(TierClaimed::class, SendNotificationWhenTierClaimed::class)
                ->listen(ItemGranted::class, SendNotificationWhenItemGranted::class)
                ->listen(TradeRequested::class, SendNotificationWhenTradeRequested::class)
                ->listen(TradeAccepted::class, SendNotificationWhenTradeAccepted::class)
                ->listen(TradeCompleted::class, SendNotificationWhenTradeCompleted::class)
                ->listen(PostTipped::class, SendNotificationWhenPostTipped::class),

            (new Notification())
                ->type(PointsManualBlueprint::class, ['alert'])
                ->type(TierClaimedBlueprint::class, ['alert'])
                ->type(ItemGrantedBlueprint::class, ['alert'])
                ->type(TradeRequestedBlueprint::class, ['alert'])
                ->type(TradeAcceptedBlueprint::class, ['alert'])
                ->type(TradeCompletedBlueprint::class, ['alert'])
                ->type(PostTippedBlueprint::class, ['alert']),
        ];
    }
}
