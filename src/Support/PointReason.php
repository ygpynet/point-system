<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

/**
 * Single source of truth for the reason codes that can appear in the points
 * ledger.
 *
 * Reasons were previously scattered magic strings ('post.posted', 'tip.out'…)
 * with no central definition. That made the ledger UI show raw codes, made them
 * impossible to translate consistently, and gave third parties no contract to
 * follow when awarding points. This registry fixes all three:
 *
 *  - Each reason carries a translatable label key (under `ygpynet-point-system.
 *    reasons.*` in the locale files).
 *  - Each reason declares whether it counts toward the daily earning cap, so the
 *    cap logic and the documentation agree in one place.
 *  - Extensions can register their own reasons at boot (e.g. via the service
 *    provider) so the ledger can label them too.
 *
 * Built-in reasons are seeded by {@see self::builtIn()}; the live registry is a
 * singleton populated by the service provider so it survives across requests in
 * long-running workers.
 */
final class PointReason
{
    public const CATEGORY_EARN = 'earn';
    public const CATEGORY_SPEND = 'spend';
    public const CATEGORY_TRANSFER = 'transfer';
    public const CATEGORY_ADMIN = 'admin';

    /**
     * @var array<string, array{label: string, category: string, countsTowardDailyCap: bool}>
     */
    private array $reasons = [];

    public function register(
        string $code,
        string $label,
        string $category = self::CATEGORY_EARN,
        bool $countsTowardDailyCap = true,
    ): void {
        $this->reasons[$code] = [
            'label' => $label,
            'category' => $category,
            'countsTowardDailyCap' => $countsTowardDailyCap,
        ];
    }

    public function has(string $code): bool
    {
        return isset($this->reasons[$code]);
    }

    /**
     * Translation key under `ygpynet-point-system.lib.reasons.*`. The `lib.`
     * segment is required for the labels to reach the FRONTEND locale JS —
     * Flarum's AddTranslations::forFrontend only packs IDs containing a
     * `.forum.` / `.admin.` / `.lib.` segment (both frontends allow `lib`).
     */
    public function labelKey(string $code): string
    {
        return 'ygpynet-point-system.lib.reasons.' . str_replace('.', '_', $code);
    }

    public function category(string $code): ?string
    {
        return $this->reasons[$code]['category'] ?? null;
    }

    public function countsTowardDailyCap(string $code): bool
    {
        return $this->reasons[$code]['countsTowardDailyCap'] ?? true;
    }

    /** @return array<string, array{label: string, category: string, countsTowardDailyCap: bool}> */
    public function all(): array
    {
        return $this->reasons;
    }

    public static function builtIn(): self
    {
        $r = new self();
        // Earning
        $r->register('discussion.started', 'discussion_started', self::CATEGORY_EARN, true);
        $r->register('post.posted', 'post_posted', self::CATEGORY_EARN, true);
        $r->register('user.registered', 'user_registered', self::CATEGORY_EARN, true);
        $r->register('like.received', 'like_received', self::CATEGORY_EARN, true);
        $r->register('like.given', 'like_given', self::CATEGORY_EARN, true);
        $r->register('checkin', 'checkin', self::CATEGORY_EARN, true);
        $r->register('daily.login', 'daily_login', self::CATEGORY_EARN, true);
        $r->register('tier.claim', 'tier_claim', self::CATEGORY_EARN, true);
        $r->register('shop.claim', 'shop_claim', self::CATEGORY_EARN, false);
        // Aliases actually written by the check-in controllers — kept beside
        // `checkin` so the registry documents every code that can appear in
        // the ledger (the label lookup uses the locale keys below).
        $r->register('user.check_in', 'checkin', self::CATEGORY_EARN, true);
        $r->register('user.checkin_makeup', 'checkin_makeup', self::CATEGORY_SPEND, false);
        // Spending
        $r->register('shop.purchase', 'shop_purchase', self::CATEGORY_SPEND, false);
        $r->register('group.purchase', 'group_purchase', self::CATEGORY_SPEND, false);
        $r->register('tip.out', 'tip_out', self::CATEGORY_SPEND, false);
        // Transfers
        $r->register('tip.in', 'tip_in', self::CATEGORY_TRANSFER, false);
        // Admin
        $r->register('admin.adjustment', 'admin_adjustment', self::CATEGORY_ADMIN, false);
        $r->register('pointSystem.manual', 'manual', self::CATEGORY_ADMIN, false);
        $r->register('admin.bulk', 'admin_bulk', self::CATEGORY_ADMIN, false);
        // Trade
        $r->register('trade.credit', 'trade_credit', self::CATEGORY_TRANSFER, false);
        $r->register('trade.debit', 'trade_debit', self::CATEGORY_SPEND, false);
        // Reverts (negative mirrors of the above) — registered so the label
        // lookup still resolves; they keep the source category.
        $r->register('discussion.started.revert', 'discussion_started_revert', self::CATEGORY_EARN, true);
        $r->register('post.posted.revert', 'post_posted_revert', self::CATEGORY_EARN, true);
        $r->register('user.registered.revert', 'user_registered_revert', self::CATEGORY_EARN, true);
        $r->register('like.received.revert', 'like_received_revert', self::CATEGORY_EARN, true);
        $r->register('like.given.revert', 'like_given_revert', self::CATEGORY_EARN, true);
        // Legacy / third-party codes observed in real ledgers.
        $r->register('user.daily_login', 'daily_login', self::CATEGORY_EARN, true);
        $r->register('giveaway.entry', 'giveaway_entry', self::CATEGORY_SPEND, false);
        $r->register('trade', 'trade', self::CATEGORY_TRANSFER, false);

        return $r;
    }
}
