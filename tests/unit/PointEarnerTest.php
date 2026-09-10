<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Listener\AwardDiscussionPoints;
use Ramon\PointSystem\Listener\AwardLikePoints;
use Ramon\PointSystem\Listener\AwardPostPoints;
use Ramon\PointSystem\Listener\InitUserPoints;
use Ramon\PointSystem\Listener\RevertLikePoints;
use Ramon\PointSystem\Points\PointEarner;
use Ramon\PointSystem\Points\PointEarnerRegistry;

/**
 * The extensibility seam: built-in earners advertise their event, and the
 * registry rejects anything that is not a {@see PointEarner}.
 */
class PointEarnerTest extends TestCase
{
    public function test_built_in_earners_declare_correct_event(): void
    {
        $this->assertSame(\Flarum\Discussion\Event\Started::class, AwardDiscussionPoints::event());
        $this->assertSame(\Flarum\Post\Event\Posted::class, AwardPostPoints::event());
        $this->assertSame(\Flarum\User\Event\Registered::class, InitUserPoints::event());
        $this->assertSame(\Flarum\Likes\Event\PostWasLiked::class, AwardLikePoints::event());
        $this->assertSame(\Flarum\Likes\Event\PostWasUnliked::class, RevertLikePoints::event());
    }

    public function test_earners_implement_the_contract(): void
    {
        foreach ([AwardDiscussionPoints::class, AwardPostPoints::class, InitUserPoints::class, AwardLikePoints::class, RevertLikePoints::class] as $earner) {
            $this->assertInstanceOf(PointEarner::class, new $earner(
                $this->createMock(\Ramon\PointSystem\Repository\PointsRepository::class)
            ));
        }
    }

    public function test_registry_rejects_non_earner(): void
    {
        $registry = new PointEarnerRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->register(\stdClass::class);
    }

    public function test_registry_dedupes(): void
    {
        $registry = new PointEarnerRegistry();
        $registry->register(AwardDiscussionPoints::class);
        $registry->register(AwardDiscussionPoints::class);

        $this->assertSame([AwardDiscussionPoints::class], $registry->all());
    }
}
