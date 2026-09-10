// @ts-nocheck
import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * Notification card shown when someone tips one of the user's posts.
 *
 * Inherits the standard `HeaderListItem` markup (avatar + icon + title/time
 * + excerpt) from the base `Notification` component — the same structure as
 * core notifications. Tapping it follows `href()` to the tipped post.
 */
export default class PostTippedNotification extends Notification {
  icon() {
    return 'fas fa-gift';
  }

  href() {
    const subject = this.attrs.notification.subject?.();
    if (subject) {
      // The subject Post may not have its `discussion` relation loaded in the
      // notification payload, which makes `app.route.post()` throw and would
      // cause HeaderListItem to render a <button> instead of an <a>. Link
      // straight to the discussion via the always-present discussion_id so the
      // card stays an <a> (matching core notification markup).
      const discussionId = subject.attribute?.('discussion_id');
      if (discussionId) {
        try {
          return app.route('discussion', { id: discussionId });
        } catch {
          // fall through
        }
      }
      try {
        return app.route.post(subject);
      } catch {
        return '';
      }
    }
    return '';
  }

  content() {
    const data = this.attrs.notification.content() || {};
    const amount = Number(data?.amount ?? 0).toLocaleString();
    const fromUser = this.attrs.notification.fromUser?.();
    const name = fromUser?.displayName?.() || app.translator.trans('ygpynet-point-system.forum.notifications.admin_fallback');
    const unit = app.forum.attribute('pointSystem.points_short') || app.translator.trans('ygpynet-point-system.forum.notifications.points_unit');
    return app.translator.trans('ygpynet-point-system.forum.notifications.post_tipped', { name, amount, unit });
  }

  excerpt() {
    const data = this.attrs.notification.content() || {};
    return data?.discussionTitle || null;
  }
}
