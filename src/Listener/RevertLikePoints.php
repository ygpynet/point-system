<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Listener;

use Flarum\Likes\Event\PostWasUnliked;
use Ramon\PointSystem\Points\PointEarner;
use Ramon\PointSystem\Repository\PointsRepository;

class RevertLikePoints implements PointEarner
{
    public function __construct(protected PointsRepository $points) {}

    #[\Override]
    public static function event(): string
    {
        return PostWasUnliked::class;
    }

    public function handle($event): void
    {
        $post = $event->post;
        $liker = $event->user;

        // like.received credits are stamped with meta['liker_id'] at award
        // time; filtering by it reverts THAT liker's credit precisely, instead
        // of whatever same-post credit happens to be newest.
        if ($post->user && $post->user->id !== $liker->id) {
            $this->points->revert($post->user, 'like.received', 'post', $post->id, null, ['liker_id' => $liker->id]);
        }
        $this->points->revert($liker, 'like.given', 'post', $post->id);
    }
}
