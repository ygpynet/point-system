// @ts-nocheck
import confetti from 'canvas-confetti';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/**
 * Cross-extension modules are resolved LAZILY here, never at module scope.
 *
 * Flarum concatenates every extension's JS into one asset and executes the
 * chunks in the enabled-extensions order (see extensions_enabled). Our chunk
 * can therefore run BEFORE fof-forum-widgets-core's chunk — a static
 * `import Widget from 'ext:fof/...'` would then evaluate reg.get() early,
 * receive `undefined`, and `class extends undefined` would kill this whole
 * chunk (blank admin page, widget never registered anywhere).
 *
 * By the time initializers RUN, every chunk has been evaluated, so resolving
 * through flarum.reg at registration/render time is always safe.
 */
function reg() {
  return (window as any).flarum.reg;
}

/** The forum app. Undefined in the admin frontend — callers must fall back. */
function forumApp() {
  return reg().get('core', 'forum/app');
}

/**
 * Builds the check-in widget component class against the given base Widget
 * class from fof/forum-widgets-core. Invoked by registerWidget() during the
 * initializer phase, when all modules are guaranteed to be loaded.
 */
export function createCheckInWidget(WidgetBase) {
  return class CheckInWidget extends WidgetBase {
    loading = false;

    className() {
      return 'PointSystemCheckInWidget';
    }

    icon() {
      return 'fas fa-calendar-check';
    }

    title() {
      const app = forumApp();
      return app
        ? app.translator.trans('ygpynet-point-system.forum.checkin.widget_title')
        : 'Daily check-in';
    }

    content(): Mithril.Children {
      const app = forumApp();
      if (!app) return null;

      const user = app.session.user;
      if (!user) return null;

      const attr = (name) => {
        try {
          return user.attribute?.(name);
        } catch (_e) {
          return undefined;
        }
      };

      const done = !!attr('pointCheckinDoneToday');
      const streak = Number(attr('pointCheckinStreak') ?? 0);
      const nextReward = Number(attr('pointCheckinNextReward') ?? 0);
      const canMakeup = !!attr('pointCheckinCanMakeup');
      const makeupRemaining = Number(attr('pointCheckinMakeupRemaining') ?? 0);
      const makeupCost = Number(app.forum.attribute('pointSystem.checkin_makeup_cost') ?? 0);
      const capDays = Number(app.forum.attribute('pointSystem.checkin_growth_cap_days') ?? 0);
      const perDayExtra = Number(app.forum.attribute('pointSystem.checkin_per_day_extra') ?? 0);
      const rank = attr('pointCheckinRankToday');
      const todayTotal = Number(attr('pointCheckinTodayTotal') ?? 0);

      return (
        <div className="PointSystemCheckInWidget-body">
          <div className="PointSystemCheckInWidget-streak" title={app.translator.trans('ygpynet-point-system.forum.checkin.streak_title', {}, true)}>
            <i className="fas fa-fire" aria-hidden="true" />{' '}
            {app.translator.trans('ygpynet-point-system.forum.checkin.streak', { count: streak })}
          </div>

          {done && rank ? (
            <div className="PointSystemCheckInWidget-rank">
              <i className="fas fa-trophy" aria-hidden="true" />{' '}
              {app.translator.trans('ygpynet-point-system.forum.checkin.rank_today', { rank })}
            </div>
          ) : null}

          {todayTotal > 0 && (
            <div className="PointSystemCheckInWidget-todayCount">
              {app.translator.trans('ygpynet-point-system.forum.checkin.today_total', { count: todayTotal })}
            </div>
          )}

          {!done && nextReward > 0 && (
            <div className="PointSystemCheckInWidget-reward">
              {app.translator.trans('ygpynet-point-system.forum.checkin.reward_today', { amount: nextReward })}
            </div>
          )}

          {!done && perDayExtra > 0 && capDays > 0 && (
            <div className="PointSystemCheckInWidget-next">{app.translator.trans('ygpynet-point-system.forum.checkin.growth_hint', { days: capDays })}</div>
          )}

          <Button
            className="Button Button--primary PointSystemCheckInWidget-button"
            icon={done ? 'fas fa-check' : 'fas fa-calendar-check'}
            disabled={done || this.loading}
            loading={this.loading}
            onclick={() => this.checkIn()}
          >
            {done ? app.translator.trans('ygpynet-point-system.forum.checkin.done') : app.translator.trans('ygpynet-point-system.forum.checkin.action')}
          </Button>

          {canMakeup && !done && (
            <Button
              className="Button PointSystemCheckInWidget-makeup"
              icon="fas fa-rotate-left"
              disabled={this.loading}
              title={app.translator.trans('ygpynet-point-system.forum.checkin.makeup_title', { cost: makeupCost, remaining: makeupRemaining }, true)}
              onclick={() => this.makeUp()}
            >
              {app.translator.trans('ygpynet-point-system.forum.checkin.makeup_action', { cost: makeupCost })}
            </Button>
          )}
        </div>
      );
    }

    protected applyState(data) {
      const user = forumApp()?.session?.user;
      if (!user || !data) return;

      user.pushAttributes({
        pointCheckinDoneToday: data.doneToday,
        pointCheckinStreak: data.streak,
        pointCheckinNextReward: data.nextReward,
        pointCheckinCanMakeup: data.canMakeup,
        pointCheckinMakeupRemaining: data.makeupRemaining,
        pointCheckinRankToday: data.rank ?? null,
        pointCheckinTodayTotal: data.todayTotal,
        pointBalance: data.balance,
        pointLifetime: data.lifetime,
      });
    }

    /**
     * Confetti burst on a successful check-in. canvas-confetti is bundled
     * locally (no CDN request) and fires on its own fullscreen canvas, then
     * cleans itself up. Skipped for users with reduced-motion enabled.
     */
    protected celebrate(): void {
      try {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

        const colors = ['#f59e0b', '#f43f5e', '#22c55e', '#3b82f6', '#a855f7', '#facc15'];

        confetti({ particleCount: 130, spread: 75, startVelocity: 42, origin: { y: 0.6 }, colors });
        // Two side cannons slightly delayed for a fuller volley.
        setTimeout(() => confetti({ particleCount: 60, angle: 60, spread: 60, origin: { x: 0, y: 0.7 }, colors }), 160);
        setTimeout(() => confetti({ particleCount: 60, angle: 120, spread: 60, origin: { x: 1, y: 0.7 }, colors }), 320);
      } catch (_e) {
        // Confetti is pure decoration — never let it break the flow.
      }
    }

    async checkIn() {
      const app = forumApp();
      const user = app?.session?.user;
      if (!user || this.loading) return;

      this.loading = true;
      m.redraw();

      try {
        const res: any = await app.request({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/point-system/checkin`,
        });

        this.applyState(res?.data);

        // Only celebrate a REAL check-in — an already-checked no-op stays quiet.
        if (res?.data?.awarded > 0) this.celebrate();

        const withRank = !!res?.data?.rank;
        app.alerts.show(
          { type: 'success' },
          app.translator.trans(
            withRank ? 'ygpynet-point-system.forum.checkin.success_rank' : 'ygpynet-point-system.forum.checkin.success',
            { amount: res?.data?.awarded, rank: res?.data?.rank, streak: res?.data?.streak }
          )
        );
      } catch (error) {
        app.alerts.show({ type: 'error' }, app.translator.trans('ygpynet-point-system.forum.checkin.error'));
      } finally {
        this.loading = false;
        m.redraw();
      }
    }

    async makeUp() {
      const app = forumApp();
      const user = app?.session?.user;
      if (!user || this.loading) return;

      this.loading = true;
      m.redraw();

      try {
        const res: any = await app.request({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/point-system/checkin/makeup`,
        });

        this.applyState(res?.data);

        if (res?.data?.paid > 0) this.celebrate();

        app.alerts.show(
          { type: 'success' },
          app.translator.trans('ygpynet-point-system.forum.checkin.makeup_success', {
            cost: res?.data?.paid,
            date: res?.data?.madeUpDate,
            streak: res?.data?.streak,
          })
        );
      } catch (error) {
        // Map the server refusal codes to friendlier messages when possible.
        const code = String(error?.response?.errors?.[0]?.code || '');
        const key =
          code === 'makeup_limit'
            ? 'ygpynet-point-system.forum.checkin.makeup_error_limit'
            : code === 'insufficient_balance'
              ? 'ygpynet-point-system.forum.checkin.makeup_error_insufficient'
              : 'ygpynet-point-system.forum.checkin.error';

        app.alerts.show({ type: 'error' }, app.translator.trans(key));
      } finally {
        this.loading = false;
        m.redraw();
      }
    }
  };
}
