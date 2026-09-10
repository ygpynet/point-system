<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Support\TransactionSerializer;

/**
 * GET /api/point-system/admin/transactions (gated on pointSystem.viewTransactions)
 *
 * Global, paginated view of every points transaction in the system, newest
 * first. Supports filtering by user (numeric id or username), exact `reason`
 * code, and an inclusive `from`/`to` date window (Y-m-d). Frontend renders
 * one row per transaction with the owner, amount, reason and timestamp.
 */
class ListTransactionsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('pointSystem.viewTransactions');

        $query = (array) $request->getQueryParams();
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));

        $builder = PointTransaction::query()->with('user')->orderByDesc('created_at');

        if (! empty($query['user'])) {
            $u = trim((string) $query['user']);
            if (is_numeric($u)) {
                $builder->where('user_id', (int) $u);
            } else {
                $user = User::query()->where('username', $u)->orWhere('id', $u)->first();
                if ($user) {
                    $builder->where('user_id', $user->id);
                } else {
                    return $this->empty($offset, $limit);
                }
            }
        }

        if (! empty($query['reason'])) {
            $builder->where('reason', (string) $query['reason']);
        }

        if (! empty($query['from'])) {
            $from = $this->dayStart((string) $query['from']);
            if ($from !== null) {
                $builder->where('created_at', '>=', $from);
            }
        }

        if (! empty($query['to'])) {
            $to = $this->dayEnd((string) $query['to']);
            if ($to !== null) {
                $builder->where('created_at', '<=', $to);
            }
        }

        $total = (clone $builder)->count();
        $rows = $builder->offset($offset)->limit($limit)->get();

        return new JsonResponse([
            'data' => $rows->map(fn (PointTransaction $t) => TransactionSerializer::serialize($t))->values()->toArray(),
            'meta' => ['total' => (int) $total, 'offset' => $offset, 'limit' => $limit],
        ]);
    }

    private function empty(int $offset, int $limit): JsonResponse
    {
        return new JsonResponse(['data' => [], 'meta' => ['total' => 0, 'offset' => $offset, 'limit' => $limit]]);
    }

    private function dayStart(string $value): ?string
    {
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d 00:00:00', $ts);
    }

    private function dayEnd(string $value): ?string
    {
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d 23:59:59', $ts);
    }
}
