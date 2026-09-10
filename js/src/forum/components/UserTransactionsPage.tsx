// @ts-nocheck
import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/utils/humanTime';
import { pointsLabel } from '../../common/utils/pointsLabel';
import { reasonLabel } from '../../common/utils/reasonLabel';
import { referenceLabel } from '../../common/utils/referenceLabel';

const PAGE_SIZE = 50;

/**
 * "Points ledger" (积分流水) tab on the user profile.
 *
 * The profile owner always sees their own ledger. Actors holding the
 * `pointSystem.viewTransactions` permission (admin/moderator audit) may also
 * open it on ANOTHER user's profile — the server scopes every request in
 * {@link ListUserTransactionsController} (owner OR permission), so the client
 * gate below is UX-only: non-owners without the permission are bounced back
 * to the profile.
 *
 * Visual language mirrors Flarum core's notification/activity feeds: a bordered
 * card of rows, each with a colored icon disc, a primary line (reason), a muted
 * meta line (reference + relative time), and the signed amount on the right.
 */
export default class UserTransactionsPage extends UserPage {
  loading = true;
  transactions: any[] = [];
  total = 0;
  offset = 0;
  err = '';
  viewingOther = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);
    const me = app.session.user;
    const isSelf = !!me && Number(me.id?.()) === Number(user.id?.());
    const canAudit = !!app.forum.attribute('pointSystemCanViewTransactions');

    if (!isSelf && !canAudit) {
      m.route.set(app.route.user(user));
      return;
    }
    this.viewingOther = !isSelf;
    this.load();
  }

  async load() {
    if (!this.user) return;
    // Keep already-loaded rows visible during pagination — a full-screen
    // spinner on every page turn reads as a broken page.
    const initial = this.transactions.length === 0;
    this.loading = initial;
    this.err = '';
    m.redraw();
    try {
      const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
      const params = new URLSearchParams();
      params.set('offset', String(this.offset));
      params.set('limit', String(PAGE_SIZE));
      const res: any = await app.request({
        method: 'GET',
        url: `${apiUrl}/point-system/users/${this.user.id?.()}/transactions?${params.toString()}`,
      });
      this.transactions = Array.isArray(res?.data) ? res.data : [];
      this.total = Number(res?.meta?.total ?? 0);
    } catch (e: any) {
      this.err =
        e?.response?.errors?.[0]?.detail ||
        (app.translator.trans('ygpynet-point-system.forum.transactions_page.load_failed') as string);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  /**
   * Export the current ledger to CSV (self-scoped, or another user's when the
   * actor holds the audit permission — the endpoint enforces that). One request
   * up to 10k rows, downloaded client-side.
   */
  async exportCsv() {
    if (!this.user) return;
    try {
      const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
      const params = new URLSearchParams();
      params.set('limit', '10000');
      const res: any = await app.request({
        method: 'GET',
        url: `${apiUrl}/point-system/users/${this.user.id?.()}/transactions?${params.toString()}`,
      });
      const rows: any[] = Array.isArray(res?.data) ? res.data : [];

      const esc = (v: any) => {
        const s = v == null ? '' : String(v);
        return '"' + s.replace(/"/g, '""') + '"';
      };
      const header = ['ID', 'Amount', 'Reason', 'Reference', 'Created'];
      const lines = rows.map((r) =>
        [r.id, r.amount, r.reason, r.referenceType ? `${r.referenceType}#${r.referenceId ?? ''}` : '', r.createdAt || '']
          .map(esc)
          .join(','),
      );
      const csv = '﻿' + [header.map(esc).join(','), ...lines].join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `point-transactions-${this.user.slug?.() ?? this.user.id?.()}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail || 'Export failed');
    }
  }

  content() {
    if (this.loading) return <LoadingIndicator />;
    const t = (k: string, v?: any) => app.translator.trans('ygpynet-point-system.forum.transactions_page.' + k, v);
    const totalPages = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const currentPage = Math.floor(this.offset / PAGE_SIZE) + 1;
    const user = this.user;
    const balance = user?.attribute?.('pointBalance');

    return (
      <div className="PointSystemTransactionsPage PointSystemTransactionsPage--inProfile container">
        <div className="PointSystemTransactionsPage-header">
          <h2 className="PointSystemTransactionsPage-title">
            <i className="fas fa-receipt" /> {t('title')}
          </h2>
          <p className="PointSystemTransactionsPage-subtitle">
            {this.viewingOther ? t('subtitle_other', { username: user?.displayName?.() ?? '' }) : t('subtitle')}
          </p>
        </div>

        <div className="PointSystemTransactionsPage-metaRow">
          {balance != null && (
            <span className="PointSystemTransactionsPage-balance" title={t('balance')}>
              <i className={(app.forum.attribute('pointSystem.currency_icon') as string) || 'fas fa-coins'} aria-hidden="true" />
              {Number(balance).toLocaleString()}
            </span>
          )}
          <span className="PointSystemTransactionsPage-spacer" />
          <Button className="Button" icon="fas fa-download" onclick={() => this.exportCsv()}>
            {t('export')}
          </Button>
        </div>

        {this.err && (
          <div className="PointSystemTransactionsPage-error">
            <i className="fas fa-triangle-exclamation" />
            <span>{this.err}</span>
            <Button className="Button Button--primary" onclick={() => this.load()}>
              {t('retry')}
            </Button>
          </div>
        )}

        {!this.err && this.transactions.length === 0 ? (
          <div className="PointSystemTransactionsPage-empty">
            <i className="fas fa-receipt" />
            <p>{t('empty')}</p>
          </div>
        ) : (
          !this.err && (
            <>
              <ul className="PointSystemTransactionsPage-list">
                {this.transactions.map((tx) => this.renderRow(tx, t))}
              </ul>

              {totalPages > 1 && (
                <div className="PointSystemTransactionsPage-pagination">
                  <Button
                    className="Button"
                    icon="fas fa-chevron-left"
                    disabled={this.offset <= 0 || this.loading}
                    onclick={() => {
                      this.offset = Math.max(0, this.offset - PAGE_SIZE);
                      this.load();
                    }}
                  >
                    {t('prev')}
                  </Button>
                  <span className="PointSystemTransactionsPage-pageInfo">{t('page_x_of_y', { x: currentPage, y: totalPages })}</span>
                  <Button
                    className="Button"
                    disabled={currentPage >= totalPages || this.loading}
                    onclick={() => {
                      this.offset = this.offset + PAGE_SIZE;
                      this.load();
                    }}
                  >
                    {t('next')} <i className="fas fa-chevron-right" />
                  </Button>
                </div>
              )}
            </>
          )
        )}
      </div>
    );
  }

  renderRow(tx: any, t: (k: string, v?: any) => any) {
    const amount = Number(tx.amount) || 0;
    const credit = amount >= 0;
    const reference = referenceLabel(tx.referenceType, tx.referenceId, app);
    const createdFull = tx.createdAt ? new Date(tx.createdAt).toLocaleString() : '';

    return (
      <li key={`tx-${tx.id}`} className="PointSystemTransactionsPage-row">
        <span className={'PointSystemTransactionsPage-icon ' + (credit ? 'is-credit' : 'is-debit')} aria-hidden="true">
          <i className={'fas ' + (credit ? 'fa-arrow-up' : 'fa-arrow-down')} />
        </span>

        <span className="PointSystemTransactionsPage-body">
          <span className="PointSystemTransactionsPage-reason" title={tx.reason}>
            {reasonLabel(tx.reason, app)}
          </span>
          <span className="PointSystemTransactionsPage-info">
            {reference && reference !== '—' ? (
              tx.referenceUrl ? (
                <Link className="PointSystemTransactionsPage-reference" href={tx.referenceUrl} title={reference}>
                  {reference}
                </Link>
              ) : (
                <span className="PointSystemTransactionsPage-reference">{reference}</span>
              )
            ) : null}
            <time className="PointSystemTransactionsPage-time" datetime={tx.createdAt || undefined} title={createdFull}>
              {tx.createdAt ? humanTime(tx.createdAt) : '—'}
            </time>
          </span>
        </span>

        <span className={'PointSystemTransaction-amount ' + (credit ? 'is-credit' : 'is-debit')}>
          {credit ? '+' : ''}
          {amount.toLocaleString()} {pointsLabel(app)}
        </span>
      </li>
    );
  }
}
