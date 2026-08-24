<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * "X tipped your post +N points" — sent to the post author after someone
 * tips their post. `getSubject()` returns the tipped Post so the frontend
 * can deep-link straight to it via `app.route.post(subject)`. `getData()`
 * carries the amount AND the parent discussion's title so the card can show
 * which topic was tipped.
 */
class PostTippedBlueprint implements BlueprintInterface, AlertableInterface
{
    public const TYPE = 'pointSystemPostTipped';

    public function __construct(
        public Post $post,
        public User $sender,
        public int $amount,
        public ?string $discussionTitle = null,
    ) {}

    #[\Override]
    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    #[\Override]
    public function getFromUser(): ?User
    {
        return $this->sender;
    }

    #[\Override]
    public function getData(): mixed
    {
        return [
            'amount' => $this->amount,
            'discussionTitle' => $this->discussionTitle,
        ];
    }

    #[\Override]
    public static function getType(): string
    {
        return self::TYPE;
    }

    #[\Override]
    public static function getSubjectModel(): string
    {
        return Post::class;
    }
}
