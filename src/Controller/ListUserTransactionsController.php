<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Foundation\KnownError\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Support\TransactionSerializer;

/**
 * GET /api/point-system/users/{id}/transactions
 *
 * Returns the ledger for a single user, newest first, paginated. The viewer
 * may read their OWN ledger unconditionally; reading someone else's requires
 * the `pointSystem.viewTransactions` permission (admin/moderator audit). The
 * frontend profile tab only ever requests the viewer's own id, but the same
 * endpoint backs the admin "view a specific user" drill-down.
 */
class ListUserTransactionsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) ($request->getAttribute('routeParameters', [])['id'] ?? 0);
        if ($id <= 0) {
            throw new RouteNotFoundException();
        }

        $user = User::findOrFail($id);

        if ($actor->id !== $user->id) {
            $actor->assertCan('pointSystem.viewTransactions');
        }

        $query = (array) $request->getQueryParams();
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));

        $builder = PointTransaction::query()
            ->with('user')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (! empty($query['reason'])) {
            $builder->where('reason', (string) $query['reason']);
        }

        $total = (clone $builder)->count();
        $rows = $builder->offset($offset)->limit($limit)->get();

        return new JsonResponse([
            'data' => $rows->map(fn (PointTransaction $t) => TransactionSerializer::serialize($t))->values()->toArray(),
            'meta' => ['total' => (int) $total, 'offset' => $offset, 'limit' => $limit],
        ]);
    }
}
