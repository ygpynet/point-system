// @ts-nocheck
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/**
 * Same lazy-resolution rationale as CheckInWidget.tsx: fof-forum-widgets-core
 * may load after our chunk, so widgets-core modules are resolved through
 * flarum.reg at build/registration time — never via static `ext:` imports.
 */
function reg() {
  return (window as any).flarum.reg;
}

function forumApp() {
  return reg().get('core', 'forum/app');
}

/**
 * "Points pool" (积分池) widget — displays the community-wide sum of every
 * member's current balance. The aggregate is computed server-side in
 * ForumAttributes (60s cache) and shipped as a public forum attribute, so
 * this widget is pure rendering: no request, no permission gate (the stat is
 * public by design; admins can hide the whole widget via the pool toggle).
 */
export function createPoolWidget(WidgetBase) {
  return class PointsPoolWidget extends WidgetBase {
    className() {
      return 'PointSystemPoolWidget';
    }

    icon() {
      return 'fas fa-piggy-bank';
    }

    title() {
      const app = forumApp();
      return app
        ? app.translator.trans('ygpynet-point-system.forum.pool.widget_title')
        : 'Points pool';
    }

    content(): Mithril.Children {
      const app = forumApp();
      if (!app) return null;

      const total = Number(app.forum.attribute('pointSystemPoolTotal') ?? 0);
      const icon = (app.forum.attribute('pointSystem.currency_icon') as string) || 'fas fa-coins';

      return (
        <div className="PointSystemPoolWidget-body">
          <div
            className="PointSystemPoolWidget-total"
            title={app.translator.trans('ygpynet-point-system.forum.pool.total_title', {}, true)}
          >
            <i className={icon} aria-hidden="true" />
            <span className="PointSystemPoolWidget-amount">{total.toLocaleString()}</span>
            <span className="PointSystemPoolWidget-unit">
              {app.translator.trans('ygpynet-point-system.forum.pool.unit')}
            </span>
          </div>
          <p className="PointSystemPoolWidget-help">
            {app.translator.trans('ygpynet-point-system.forum.pool.help')}
          </p>
        </div>
      );
    }
  };
}
