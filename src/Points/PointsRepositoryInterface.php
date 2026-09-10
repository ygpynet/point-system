<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Points;

use Flarum\User\User;
use Ramon\PointSystem\Model\PointTransaction;

/**
 * Public, stable contract for everything that moves or reads points.
 *
 * Internal code calls the concrete {@see \Ramon\PointSystem\Repository\PointsRepository};
 * other extensions should type-hint THIS interface so the implementation can be
 * swapped (e.g. for tests or an external ledger) without touching call sites.
 * The service provider binds the concrete class to this interface as a singleton.
 */
interface PointsRepositoryInterface
{
    public function isEnabled(): bool;

    public function getOrCreate(User $user): \Ramon\PointSystem\Model\UserPoints;

    public function award(
        User $user,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $meta = null,
        bool $bypassCap = false,
    ): ?PointTransaction;

    public function revert(
        User $user,
        string $reason,
        string $referenceType,
        int $referenceId,
    ): void;

    public function deduct(
        User $user,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): PointTransaction;

    /**
     * Atomically move points from one user to another inside a single database
     * transaction (the sender is balance-checked, the receiver is exempt from the
     * daily cap). Use this instead of calling deduct()+award() separately so the
     * two writes can never land in an inconsistent split state.
     */
    public function transfer(
        User $from,
        User $to,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void;

    public function syncAutoGroups(User $user, ?\Ramon\PointSystem\Model\UserPoints $points = null): void;

    public function clearAutoOffersCache(): void;

    public function settingInt(string $key, int $default = 0): int;
}
