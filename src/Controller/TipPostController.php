<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\User\Exception\NotAuthenticatedException;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Event\PostTipped;
use Ramon\PointSystem\Model\PostTip;
use Ramon\PointSystem\Points\TipRateLimiter;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * POST /api/point-system/tip
 * Body: { postId: int, amount: int }
 *
 * Transfers points from the actor to the post author.
 *
 * The actual balance movement is delegated to {@see PointsRepository}:
 * `deduct()` (actor, atomic, balance-checked, ledger, auto-group sync) and
 * `award()` (recipient). Both take a `SELECT … FOR UPDATE` row lock *inside*
 * their own transaction, so the balance is checked and mutated under the lock
 * — there is no window where two concurrent tips could both pass a pre-check
 * and drive the sender's balance negative (the TOCTOU the old hand-rolled
 * decrement had). Keeping tips on the same engine as claims/trades also means
 * auto-group membership stays consistent and the point ledger is uniform.
 */
class TipPostController implements RequestHandlerInterface
{
    public function __construct(
        protected PointsRepository $points,
        protected Dispatcher $events,
        protected TipRateLimiter $rateLimiter,
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

        if (! $this->points->isEnabled()) {
            return new JsonResponse(['errors' => [['detail' => 'Points are disabled']]], 422);
        }

        // Abuse guard: cap how many tips a single user may send per rolling hour
        // so the endpoint can't be scripted to launder points or spam authors.
        if ($this->rateLimiter->isLimited($actor->id)) {
            return new JsonResponse(
                ['errors' => [['detail' => 'Tip limit reached. Please try again later.']]],
                429,
            );
        }

        try {
            // One atomic unit: the sender is balance-checked and debited while the
            // recipient is credited, inside a single database transaction. If the
            // sender can't cover it, NOTHING changes. Tips received bypass the
            // daily earning cap. The ledger rows + auto-group sync happen inside
            // each leg, and the tip record is written after the move commits.
            $this->points->transfer($actor, $recipient, $amount, 'tip', Post::class, $postId);

            // Record the tip so the post can show who tipped how much.
            // Stackable by design — a repeat tip is a fresh row.
            PostTip::create([
                'post_id' => $postId,
                'sender_id' => $actor->id,
                'recipient_id' => $recipient->id,
                'amount' => $amount,
            ]);
        } catch (\DomainException $e) {
            return new JsonResponse(['errors' => [['detail' => 'Insufficient points']]], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['errors' => [['detail' => 'Transaction failed']]], 500);
        }

        // Notify the author AFTER the commit so a notification row is only
        // ever written for a tip that actually landed. The dedicated
        // PostTipped event replaces the generic "points changed" notice.
        $this->events->dispatch(new PostTipped($post, $actor, $recipient, $amount));

        return new JsonResponse(['data' => [
            'newBalance' => (int) $this->points->getOrCreate($actor)->balance,
            // Return the recipient's (post author's) updated balance too, so
            // the forum can push it straight into the store and refresh the
            // post-header points badge live — without depending on the
            // `pointSystem.viewOthers` permission or on `post.refresh()`
            // re-including the author with a visible `pointBalance`.
            'recipientId' => (int) $recipient->id,
            'recipientNewBalance' => (int) $this->points->getOrCreate($recipient)->balance,
        ]]);
    }
}
