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
 * "Points pool" (积分池) widget.
 *
 * Two display modes, driven by the admin-set budget (`pointSystem.pool_size`):
 *
 *  - Budget mode (size > 0): ISSUED = Σ of every member's current balance
 *    (pointSystemPoolTotal — however the points were earned); REMAINING =
 *    budget − issued. The accounting is self-maintaining: rule earnings /
 *    admin awards grow the issued sum and drain the pool, while burns
 *    (giveaway entries, shop claims…) shrink it and refill the pool.
 *  - Total-only mode (size = 0): simply shows the community-wide balance sum.
 *
 * The aggregate is computed server-side in ForumAttributes (60s cache) and
 * shipped as a public forum attribute — this widget is pure rendering.
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
      return app ? app.translator.trans('ygpynet-point-system.forum.pool.widget_title') : 'Points pool';
    }

    content(): Mithril.Children {
      const app = forumApp();
      if (!app) return null;

      const t = (k: string, v?: any) => app.translator.trans('ygpynet-point-system.forum.pool.' + k, v);
      // ISSUED = points users currently hold; REMAINING = budget − issued.
      // Both can be negative only when the admin sets a budget smaller than
      // the already-circulating supply — the red overdrawn state surfaces
      // that misconfiguration honestly.
      const issued = Number(app.forum.attribute('pointSystemPoolTotal') ?? 0);
      const size = Number(app.forum.attribute('pointSystem.pool_size') ?? 0);

      // Budget not set → total-only view (original behaviour).
      if (size <= 0) {
        return this.totalOnlyView(t, issued);
      }

      const remaining = size - issued;
      // Clamp the bar at 0–100%; an overdrawn budget shows 100% filled and
      // the remaining figure itself turns red via is-overdrawn.
      const pct = Math.max(0, Math.min(100, (issued / size) * 100));

      return (
        <div className="PointSystemPoolWidget-body">
          <div
            className={'PointSystemPoolWidget-total' + (remaining < 0 ? ' is-overdrawn' : '')}
            title={t('remaining_title', { remaining: remaining.toLocaleString(), size: size.toLocaleString(), issued: issued.toLocaleString() }, true)}
          >
            <i className={(app.forum.attribute('pointSystem.currency_icon') as string) || 'fas fa-coins'} aria-hidden="true" />
            <span className="PointSystemPoolWidget-amount">{remaining.toLocaleString()}</span>
            <span className="PointSystemPoolWidget-unit">{t('remaining')}</span>
          </div>

          <div
            className="PointSystemPoolWidget-progressBar"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow={Math.round(pct)}
          >
            <span style={'width:' + pct + '%'} />
          </div>

          <div className="PointSystemPoolWidget-meta">{t('of_size', { issued: issued.toLocaleString(), size: size.toLocaleString() })}</div>
        </div>
      );
    }

    totalOnlyView(t: (k: string, v?: any) => any, total: number): Mithril.Children {
      const app = forumApp();
      const icon = (app.forum.attribute('pointSystem.currency_icon') as string) || 'fas fa-coins';

      return (
        <div className="PointSystemPoolWidget-body">
          <div className="PointSystemPoolWidget-total" title={t('total_title', {}, true)}>
            <i className={icon} aria-hidden="true" />
            <span className="PointSystemPoolWidget-amount">{total.toLocaleString()}</span>
            <span className="PointSystemPoolWidget-unit">{t('unit')}</span>
          </div>
          <p className="PointSystemPoolWidget-help">{t('help')}</p>
        </div>
      );
    }
  };
}
