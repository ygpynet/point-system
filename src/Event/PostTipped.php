<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Event;

use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Dispatched by {@see \Ramon\PointSystem\Controller\TipPostController}
 * after a successful tip transfer. The listener notifies the post author
 * so they know who tipped them and how much — replacing the generic
 * "points changed" notification with one tailored to tips.
 */
class PostTipped
{
    public function __construct(
        public Post $post,
        public User $sender,
        public User $recipient,
        public int $amount,
    ) {}
}
