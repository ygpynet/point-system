// @ts-nocheck
import { createCheckInWidget } from './components/CheckInWidget';
import { createPoolWidget } from './components/PointsPoolWidget';

/**
 * Registers the check-in widget with fof/forum-widgets-core. Admins can then
 * drag it between slots (start_top / start_bottom / end / top / bottom) and
 * reorder it in the Widgets admin page; `placement: 'end'` is only the
 * default — the right-hand sidebar on the index page.
 *
 * Everything here runs during the initializer phase, AFTER all extension
 * chunks have been evaluated, so resolving widgets-core modules through
 * flarum.reg lazily is safe regardless of chunk execution order (see the
 * note in CheckInWidget.tsx). A static `ext:` import would evaluate at chunk
 * load time and break whenever our extension loads before widgets-core.
 */
export default function registerWidget(app): void {
  const reg = (window as any).flarum.reg;
  const WidgetBase = reg.get('fof-forum-widgets-core', 'common/components/Widget');
  const Widgets = reg.get('fof-forum-widgets-core', 'common/extend/Widgets');

  if (!WidgetBase || !Widgets) {
    console.warn('[ramon/point-system] fof/forum-widgets-core missing — check-in widget not registered.');
    return;
  }

  try {
    new Widgets()
      .add({
        key: 'pointSystemCheckIn',
        component: createCheckInWidget(WidgetBase),
        isDisabled: () => !app.session.user,
        isUnique: true,
        placement: 'end',
        position: 1,
      })
      .add({
        // 积分池 — public stat, hidden entirely when the admin turns the
        // feature off (isDisabled keeps it out of the Widgets admin UI too).
        key: 'pointSystemPool',
        component: createPoolWidget(WidgetBase),
        isDisabled: () => !app.forum.attribute('pointSystemPoolEnabled'),
        isUnique: true,
        placement: 'end',
        position: 2,
      })
      .extend(app, 'ygpynet-point-system');
  } catch (e) {
    console.warn('[ramon/point-system] widget registration failed:', e);
  }
}
