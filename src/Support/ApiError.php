<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * Single factory for the error envelope consumed by the frontend.
 *
 * Shape contract: `errors[0].code` is a STABLE machine token the JS uses for
 * branching (CheckInWidget, AdminTradeDetailModal), and `errors[0].detail` is
 * what gets displayed verbatim by most components. That rules out Flarum's
 * ValidationException — it drops `code` — so responses keep the shape but
 * `detail` is now rendered server-side from `ygpynet-point-system.lib.errors.*`
 * instead of leaking internal strings like `sold_out` or `Insufficient point
 * balance`. The domain-message → key mapping lives here so a new refusal code
 * is added in exactly one place.
 */
final class ApiError
{
    private const KEY_PREFIX = 'ygpynet-point-system.lib.errors.';

    /**
     * DomainException message → translation key + stable `code` token.
     * Availability codes come from ItemAvailability::reasonNotClaimable(),
     * the balance message from PointsRepository::deduct().
     */
    private const DOMAIN_MAP = [
        'disabled' => 'item_disabled',
        'not_yet_available' => 'item_not_yet_available',
        'expired' => 'item_expired',
        'sold_out' => 'item_sold_out',
        'group_restricted' => 'item_group_restricted',
        'Insufficient point balance' => 'insufficient_balance',
    ];

    public function __construct(
        protected TranslatorInterface $translator,
    ) {}

    public function unprocessable(string $code, string $key): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => $code, 'detail' => $this->trans($key)]]],
            422,
        );
    }

    public function fromDomain(\DomainException $e): JsonResponse
    {
        $key = self::DOMAIN_MAP[$e->getMessage()] ?? null;

        if ($key === null) {
            return new JsonResponse(
                ['errors' => [['code' => $e->getMessage(), 'detail' => $e->getMessage()]]],
                422,
            );
        }

        return $this->unprocessable($key, $key);
    }

    private function trans(string $key): string
    {
        $id = self::KEY_PREFIX.$key;
        $translated = $this->translator->trans($id);

        return $translated === $id ? $key : $translated;
    }
}
