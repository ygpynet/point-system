<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Settings;

/**
 * All admin-controllable settings: the earning rules, currency display,
 * decoration toggles, trade/submission/tip switches and their defaults. Kept
 * as one module so the configuration contract of the extension is in one place.
 */
class SettingsModule implements ModuleInterface
{
    public function extenders(): array
    {
        return [
            (new Settings())
                ->serializeToForum('pointSystem.enabled', 'point-system.enabled', 'boolval')
                ->serializeToForum('pointSystem.points_per_discussion', 'point-system.points_per_discussion', 'intval')
                ->serializeToForum('pointSystem.points_per_post', 'point-system.points_per_post', 'intval')
                ->serializeToForum('pointSystem.points_per_like_received', 'point-system.points_per_like_received', 'intval')
                ->serializeToForum('pointSystem.points_per_like_given', 'point-system.points_per_like_given', 'intval')
                ->serializeToForum('pointSystem.points_per_registration', 'point-system.points_per_registration', 'intval')
                ->serializeToForum('pointSystem.checkin_base_points', 'point-system.checkin_base_points', 'intval')
                ->serializeToForum('pointSystem.checkin_per_day_extra', 'point-system.checkin_per_day_extra', 'intval')
                ->serializeToForum('pointSystem.checkin_growth_cap_days', 'point-system.checkin_growth_cap_days', 'intval')
                ->serializeToForum('pointSystem.checkin_makeup_cost', 'point-system.checkin_makeup_cost', 'intval')
                ->serializeToForum('pointSystem.daily_earn_cap', 'point-system.daily_earn_cap', 'intval')
                ->serializeToForum('pointSystem.currency_name', 'point-system.currency_name')
                ->serializeToForum('pointSystem.currency_icon', 'point-system.currency_icon')
                ->serializeToForum('pointSystem.points_short', 'point-system.points_short')
                ->serializeToForum('pointSystem.show_in_post_header', 'point-system.show_in_post_header', 'boolval')
                ->serializeToForum('pointSystem.show_in_user_profile', 'point-system.show_in_user_profile', 'boolval')
                ->serializeToForum('pointSystem.lifetime_enabled', 'point-system.lifetime_enabled', 'boolval')
                ->serializeToForum('pointSystem.auto_group_enabled', 'point-system.auto_group_enabled', 'boolval')
                ->serializeToForum('pointSystem.avatar_deco_enabled', 'point-system.avatar_deco_enabled', 'boolval')
                ->serializeToForum('pointSystem.name_deco_enabled', 'point-system.name_deco_enabled', 'boolval')
                ->serializeToForum('pointSystem.cover_deco_enabled', 'point-system.cover_deco_enabled', 'boolval')
                ->serializeToForum('pointSystem.title_deco_enabled', 'point-system.title_deco_enabled', 'boolval')
                ->serializeToForum('pointSystem.post_hl_deco_enabled', 'point-system.post_hl_deco_enabled', 'boolval')
                ->serializeToForum('pointSystem.deco_in_posts', 'point-system.deco_in_posts', 'boolval')
                ->serializeToForum('pointSystem.deco_in_user_card', 'point-system.deco_in_user_card', 'boolval')
                ->serializeToForum('pointSystem.deco_in_lists', 'point-system.deco_in_lists', 'boolval')
                ->serializeToForum('pointSystem.avatar_deco_in_lists', 'point-system.avatar_deco_in_lists', 'boolval')
                ->serializeToForum('pointSystem.hide_badges_with_avatar_deco', 'point-system.hide_badges_with_avatar_deco', 'boolval')
                ->serializeToForum('pointSystem.trade_enabled', 'point-system.trade_enabled', 'boolval')
                ->serializeToForum('pointSystem.user_submissions_enabled', 'point-system.user_submissions_enabled', 'boolval')
                ->serializeToForum('pointSystem.tip_enabled', 'point-system.tip_enabled', 'boolval')
                ->serializeToForum('pointSystem.pool_enabled', 'point-system.pool_enabled', 'boolval')
                ->serializeToForum('pointSystem.tip_preset_amounts', 'point-system.tip_preset_amounts')
                ->serializeToForum('pointSystem.tip_hourly_limit', 'point-system.tip_hourly_limit', 'intval')
                ->default('point-system.enabled', true)
                ->default('point-system.points_per_discussion', 10)
                ->default('point-system.points_per_post', 5)
                ->default('point-system.points_per_like_received', 2)
                ->default('point-system.points_per_like_given', 1)
                ->default('point-system.points_per_registration', 50)
                ->default('point-system.checkin_base_points', 5)
                ->default('point-system.checkin_per_day_extra', 1)
                ->default('point-system.checkin_growth_cap_days', 7)
                ->default('point-system.checkin_makeup_cost', 10)
                ->default('point-system.checkin_makeup_max_streak', 3)
                ->default('point-system.daily_earn_cap', 0)
                ->default('point-system.currency_name', 'Points')
                ->default('point-system.currency_icon', 'fas fa-coins')
                ->default('point-system.points_short', 'pts')
                ->default('point-system.show_in_post_header', true)
                ->default('point-system.show_in_user_profile', true)
                ->default('point-system.lifetime_enabled', true)
                ->default('point-system.auto_group_enabled', true)
                ->default('point-system.avatar_deco_enabled', true)
                ->default('point-system.name_deco_enabled', true)
                ->default('point-system.cover_deco_enabled', true)
                ->default('point-system.title_deco_enabled', true)
                ->default('point-system.post_hl_deco_enabled', true)
                ->default('point-system.deco_in_posts', true)
                ->default('point-system.deco_in_user_card', true)
                ->default('point-system.deco_in_lists', true)
                ->default('point-system.avatar_deco_in_lists', true)
                ->default('point-system.hide_badges_with_avatar_deco', false)
                ->default('point-system.trade_enabled', true)
                ->default('point-system.user_submissions_enabled', false)
                ->default('point-system.tip_enabled', true)
                ->default('point-system.pool_enabled', true)
                ->default('point-system.tip_preset_amounts', '[5,10,15,20]')
                ->default('point-system.tip_hourly_limit', 0),
        ];
    }
}
