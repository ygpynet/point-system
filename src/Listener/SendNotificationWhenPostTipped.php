<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Listener;

use Flarum\Notification\NotificationSyncer;
use Psr\Log\LoggerInterface;
use Ramon\PointSystem\Event\PostTipped;
use Ramon\PointSystem\Notification\PostTippedBlueprint;
use Throwable;

class SendNotificationWhenPostTipped
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected LoggerInterface $logger,
    ) {}

    public function handle(PostTipped $event): void
    {
        $post = $event->post;
        $sender = $event->sender;
        $recipient = $event->recipient;

        $post->loadMissing(['user', 'discussion']);
        $sender->loadMissing(['pointsBalance']);
        $recipient->loadMissing(['pointsBalance']);

        // Defensive self-exclude (the controller already blocks self-tips).
        if ((int) $sender->id === (int) $recipient->id) {
            return;
        }

        // Snapshot the discussion title so the notification shows which topic
        // was tipped even if the title is edited later.
        $discussionTitle = $post->discussion?->title;

        try {
            $this->notifications->sync(
                new PostTippedBlueprint($post, $sender, $event->amount, $discussionTitle),
                [$recipient],
            );
        } catch (Throwable $e) {
            $this->logger->warning('point-system: failed to send post-tipped notification', [
                'post_id'    => (int) $post->id,
                'sender_id'  => (int) $sender->id,
                'recipient_id' => (int) $recipient->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
