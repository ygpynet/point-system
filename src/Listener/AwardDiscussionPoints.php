<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Listener;

use Flarum\Discussion\Event\Started;
use Ramon\PointSystem\Points\PointEarner;
use Ramon\PointSystem\Repository\PointsRepository;

class AwardDiscussionPoints implements PointEarner
{
    public function __construct(protected PointsRepository $points) {}

    #[\Override]
    public static function event(): string
    {
        return Started::class;
    }

    public function handle(Started $event): void
    {
        $amount = $this->points->settingInt('point-system.points_per_discussion', 10);
        if ($amount <= 0 || ! $event->discussion->user) {
            return;
        }

        $this->points->award(
            $event->discussion->user,
            $amount,
            'discussion.started',
            'discussion',
            $event->discussion->id,
        );
    }
}
