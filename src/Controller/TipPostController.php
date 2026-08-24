<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\User\Exception\NotAuthenticatedException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Event\PointsManuallyChanged;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * POST /api/point-system/tip
 * Body: { postId: int, amount: int }
 *
 * Transfers points from the actor to the post author.
 */
class TipPostController implements RequestHandlerInterface
{
    use \Flarum\Foundation\DispatchEventsTrait;

    public function __construct(
        protected ConnectionInterface $db,
        protected PointsRepository $points,
        protected Dispatcher $events,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! $actor) {
            throw new NotAuthenticatedException();
        }

        $body = (array) $request->getParsedBody();
        $postId = (int) ($body['postId'] ?? 0);
        $amount = (int) ($body['amount'] ?? 0);

        if ($amount <= 0) {
            return new JsonResponse(['errors' => [['detail' => 'Invalid amount']]], 422);
        }

        $post = Post::query()->find($postId);
        if (! $post) {
            return new JsonResponse(['errors' => [['detail' => 'Post not found']]], 404);
        }

        $recipient = $post->user()->first();
        if (! $recipient) {
            return new JsonResponse(['errors' => [['detail' => 'Post author not found']]], 404);
        }

        // Cannot tip yourself.
        if ($actor->id === $recipient->id) {
            return new JsonResponse(['errors' => [['detail' => 'You cannot tip yourself']]], 422);
        }

        $actorPoints = UserPoints::query()->where('user_id', $actor->id)->first();
        if (! $actorPoints || $actorPoints->balance < $amount) {
            return new JsonResponse(['errors' => [['detail' => 'Insufficient points']]], 422);
        }

        $this->db->beginTransaction();

        try {
            $actorPoints->decrement('balance', $amount);

            $recipientPoints = UserPoints::query()->firstOrCreate(
                ['user_id' => $recipient->id],
                ['balance' => 0, 'lifetime' => 0]
            );
            $recipientPoints->increment('balance', $amount);
            $recipientPoints->increment('lifetime', $amount);

            PointTransaction::create([
                'user_id' => $actor->id,
                'amount' => -$amount,
                'reason' => "Tipped post #{$postId}",
                'reference_type' => Post::class,
                'reference_id' => $postId,
                'meta' => ['type' => 'tip_out', 'recipient_id' => $recipient->id],
            ]);

            PointTransaction::create([
                'user_id' => $recipient->id,
                'amount' => $amount,
                'reason' => "Received tip for post #{$postId}",
                'reference_type' => Post::class,
                'reference_id' => $postId,
                'meta' => ['type' => 'tip_in', 'sender_id' => $actor->id],
            ]);

            // Raise events so notifications fire consistently.
            $actorPoints->raise(new PointsManuallyChanged($actor, $actor, -$amount, 'tip_out'));
            $recipientPoints->raise(new PointsManuallyChanged($recipient, $actor, $amount, 'tip_in'));
            $this->dispatchEventsFor($actorPoints, $actor);
            $this->dispatchEventsFor($recipientPoints, $actor);

            $this->db->commit();

            return new JsonResponse(['data' => [
                'newBalance' => $actorPoints->fresh()->balance,
            ]]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            return new JsonResponse(['errors' => [['detail' => 'Transaction failed']]], 500);
        }
    }
}
