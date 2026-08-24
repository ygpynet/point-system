// @ts-nocheck
import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Post from 'flarum/common/models/Post';

export default class TipPostModal extends Modal {
  selectedAmount: number = 0;
  customAmount: string = '';
  busy = false;

  className() {
    return 'TipPostModal Modal--small';
  }

  title() {
    return app.translator.trans('ramon-point-system.forum.tip_modal.title');
  }

  content() {
    const t = (k: string) => app.translator.trans('ramon-point-system.forum.tip_modal.' + k);
    const balance = Number(app.session.user?.attribute('pointBalance') ?? 0);
    const presetAmounts = this.getPresetAmounts();

    return (
      <div className="Modal-body">
        <div className="TipPostModal-balance">
          <i className={app.forum.attribute('pointSystem.currency_icon') || 'fas fa-coins'} /> {t('your_balance')}: <strong>{balance.toLocaleString()}</strong>
        </div>

        <div className="TipPostModal-presets">
          <label>{t('select_amount')}</label>
          <div className="TipPostModal-presetButtons">
            {presetAmounts.map((amount) => (
              <Button
                key={amount}
                className={`Button ${this.selectedAmount === amount ? 'Button--primary' : ''}`}
                onclick={() => this.selectPreset(amount)}
                disabled={amount > balance}
              >
                {amount}
              </Button>
            ))}
          </div>
        </div>

        <div className="Form-group">
          <label>{t('custom_amount')}</label>
          <input
            type="number"
            className="FormControl"
            min="1"
            step="1"
            value={this.customAmount}
            oninput={(e: Event) => {
              this.customAmount = (e.target as HTMLInputElement).value;
              this.selectedAmount = 0;
            }}
            placeholder={t('custom_placeholder') as string}
          />
        </div>

        <div className="TipPostModal-actions">
          <Button
            className="Button Button--primary Button--block"
            loading={this.busy}
            disabled={(!this.selectedAmount && !this.customAmount) || this.busy}
            onclick={() => this.submit()}
          >
            <i className="fas fa-gift" /> {t('confirm_tip')}
          </Button>
        </div>
      </div>
    );
  }

  getPresetAmounts(): number[] {
    const raw = app.forum.attribute('pointSystem.tip_preset_amounts');
    if (Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
      try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed;
      } catch {}
    }
    return [5, 10, 15, 20];
  }

  selectPreset(amount: number) {
    this.selectedAmount = amount;
    this.customAmount = '';
  }

  getAmount(): number {
    if (this.selectedAmount > 0) return this.selectedAmount;
    const custom = parseInt(this.customAmount, 10);
    return custom > 0 ? custom : 0;
  }

  async submit() {
    const amount = this.getAmount();
    if (amount <= 0) return;

    this.busy = true;
    m.redraw();

    try {
      const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
      const res: any = await app.request({
        method: 'POST',
        url: `${apiUrl}/point-system/tip`,
        body: {
          postId: Number(this.attrs.post.id()),
          amount,
        },
      });

      const data = res?.data || res;
      if (data?.newBalance !== undefined) {
        app.session.user?.pushAttributes({
          pointBalance: Number(data.newBalance),
        });
      }

      // Push the recipient's (post author's) updated balance straight into the
      // store so the post-header points badge refreshes live, independent of
      // `post.refresh()` re-including the author with a visible `pointBalance`.
      // Only when the actor can already see others' points (the attribute is
      // present on the model) — otherwise the header doesn't show it anyway,
      // and we avoid leaking the total via the response payload.
      if (data?.recipientId !== undefined && data?.recipientNewBalance !== undefined) {
        const recipient = app.store.getById('users', String(data.recipientId));
        if (recipient && recipient.attribute('pointBalance') !== undefined) {
          recipient.pushAttributes({ pointBalance: Number(data.recipientNewBalance) });
        }
      }

      // Re-fetch the post so the on-post "tipped by" summary updates live.
      try {
        this.attrs.post?.refresh?.();
      } catch {}

      app.alerts.show(
        { type: 'success' },
        app.translator.trans('ramon-point-system.forum.tip_modal.success', { amount: amount.toLocaleString() })
      );
      this.hide();
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail || app.translator.trans('ramon-point-system.forum.tip_modal.error'));
    } finally {
      this.busy = false;
      m.redraw();
    }
  }
}
