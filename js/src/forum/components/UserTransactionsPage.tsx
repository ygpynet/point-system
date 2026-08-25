// @ts-nocheck
import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { pointsLabel } from '../../common/utils/pointsLabel';
import { reasonLabel } from '../../common/utils/reasonLabel';

const PAGE_SIZE = 50;

/**
 * "Points ledger" (积分流水) tab on the user profile — visible only when the
 * viewer IS the profile owner. Backed by
 * GET /api/point-system/users/{id}/transactions, which the server scopes to
 * the authenticated actor; we additionally bounce non-owners on mount so the
 * UI never shows a confusingly empty page under someone else's slug.
 */
export default class UserTransactionsPage extends UserPage {
  loading = true;
  transactions: any[] = [];
  total = 0;
  offset = 0;
  err = '';

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);
    const me = app.session.user;
    if (!me || Number(me.id?.()) !== Number(user.id?.())) {
      m.route.set(app.route.user(user));
      return;
    }
    this.load();
  }

  async load() {
    if (!this.user) return;
    this.loading = true;
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
      this.err = e?.response?.errors?.[0]?.detail || (app.translator.trans('ygpynet-point-system.forum.transactions_page.load_failed') as string);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  /**
   * Export the viewer's own ledger to CSV (self-scoped endpoint, no filters).
   * One request up to 10k rows, downloaded client-side.
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
        [
          r.id,
          r.amount,
          r.reason,
          r.referenceType ? `${r.referenceType}#${r.referenceId ?? ''}` : '',
          r.createdAt || '',
        ].map(esc).join(','),
      );
      const csv = '﻿' + [header.map(esc).join(','), ...lines].join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'my-point-transactions.csv';
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

    return (
      <div className="PointSystemTransactionsPage PointSystemTransactionsPage--inProfile container">
        <div className="PointSystemTransactionsPage-header">
          <h1>
            <i className="fas fa-receipt" /> {t('title')}
          </h1>
          <p className="helpText">{t('subtitle')}</p>
          <div className="PointSystemTransactionsPage-actions">
            <Button className="Button" onclick={() => this.exportCsv()}>
              <i className="fas fa-download" /> {t('export')}
            </Button>
          </div>
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
          <p className="PointSystemTransactionsPage-empty">{t('empty')}</p>
        ) : (
          !this.err && (
            <>
              <table className="PointSystemTransactionsPage-table">
                <thead>
                  <tr>
                    <th>{t('col_amount')}</th>
                    <th>{t('col_reason')}</th>
                    <th>{t('col_reference')}</th>
                    <th>{t('col_created')}</th>
                  </tr>
                </thead>
                <tbody>{this.transactions.map((tx) => this.renderRow(tx, t))}</tbody>
              </table>

              {totalPages > 1 && (
                <div className="PointSystemTransactionsPage-pagination">
                  <Button
                    className="Button"
                    disabled={this.offset <= 0}
                    onclick={() => {
                      this.offset = Math.max(0, this.offset - PAGE_SIZE);
                      this.load();
                    }}
                  >
                    <i className="fas fa-chevron-left" /> {t('prev')}
                  </Button>
                  <span className="PointSystemTransactionsPage-pageInfo">{t('page_x_of_y', { x: currentPage, y: totalPages })}</span>
                  <Button
                    className="Button"
                    disabled={currentPage >= totalPages}
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
    const sign = amount >= 0 ? '+' : '';
    const reference = tx.referenceType
      ? `${tx.referenceType}${tx.referenceId != null ? ' #' + tx.referenceId : ''}`
      : '—';

    return (
      <tr key={`tx-${tx.id}`} className="PointSystemTransactionsPage-row">
        <td>
          <span className={'PointSystemTransaction-amount ' + (amount >= 0 ? 'is-credit' : 'is-debit')}>
            {sign}
            {amount.toLocaleString()} {pointsLabel(app)}
          </span>
        </td>
        <td>
          <code className="PointSystemTransaction-reason" title={tx.reason}>
            {reasonLabel(tx.reason, app)}
          </code>
        </td>
        <td className="muted">
          <small>{reference}</small>
        </td>
        <td>
          <small className="muted">{tx.createdAt ? new Date(tx.createdAt).toLocaleString() : '—'}</small>
        </td>
      </tr>
    );
  }
}
