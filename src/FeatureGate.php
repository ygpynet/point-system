<?php

declare(strict_types=1);

namespace Ramon\PointSystem;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Ramon\PointSystem\Support\DecorationRegistry;

/**
 * Single source of truth for decoration feature toggles AND the trade-
 * subsystem toggle.
 *
 * Each decoration type (avatar / name / cover / title / post-highlight) is
 * paired with an admin-controlled `*_deco_enabled` setting. When the setting
 * is off, both the public catalog (ForumAttributes) AND the API endpoints
 * that mutate that decoration type must refuse the request. The same idea
 * applies to `trade_enabled` for the trade subsystem.
 *
 * The type→setting mapping lives in {@see DecorationRegistry} (so a new
 * decoration family is added in exactly one place); this gate asks the
 * registry for the right setting key rather than holding its own copy.
 *
 * Controllers must call `assertEnabled()` / `assertTradeEnabled()` BEFORE
 * any DB write — the gate is the single line that prevents a feature from
 * coming back through a manual API call once the admin has flipped it off.
 */
class FeatureGate
{
    public const TRADE_SETTING = 'point-system.trade_enabled';

    public const USER_SUBMISSIONS_SETTING = 'point-system.user_submissions_enabled';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected DecorationRegistry $registry,
    ) {}

    public function isEnabled(string $type): bool
    {
        $key = $this->registry->settingFor($type);
        if ($key === null) {
            return false;
        }
        return (bool) $this->settings->get($key, true);
    }

    /**
     * Throw 403 when the decoration type is disabled at admin level. Use this
     * before any mutating API action (claim / equip / upload / delete / create).
     */
    public function assertEnabled(string $type): void
    {
        if (! $this->isEnabled($type)) {
            throw new PermissionDeniedException();
        }
    }

    /** True when the admin allows trade sessions globally. */
    public function isTradeEnabled(): bool
    {
        return (bool) $this->settings->get(self::TRADE_SETTING, true);
    }

    /**
     * Throw 403 when trading is disabled at admin level. Called by every
     * trade controller — opening, updating, accepting, cancelling.
     * Cancelling-when-disabled stays allowed: a participant should be able
     * to close out a pending trade an admin has just turned off, otherwise
     * the row sits forever.
     */
    public function assertTradeEnabled(): void
    {
        if (! $this->isTradeEnabled()) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * True when admin allows regular users to submit their own decoration
     * designs. Submissions land in `status = pending` and require admin
     * approval before they ship to the public shop. Default OFF — admins
     * have to opt in so no forum gets a flood of user submissions just by
     * installing the extension.
     */
    public function isUserSubmissionsEnabled(): bool
    {
        return (bool) $this->settings->get(self::USER_SUBMISSIONS_SETTING, false);
    }

    public function assertUserSubmissionsEnabled(): void
    {
        if (! $this->isUserSubmissionsEnabled()) {
            throw new PermissionDeniedException();
        }
    }
}
