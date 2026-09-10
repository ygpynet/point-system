// @ts-nocheck
import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import { pointsLabel } from '../../common/utils/pointsLabel';
import { reasonLabel } from '../../common/utils/reasonLabel';
import { referenceLabel } from '../../common/utils/referenceLabel';

const PAGE_SIZE = 50;

/**
 * Admin "Points ledger" (积分流水) panel — lists every points transaction in
 * the system across all users, newest first, paginated. Backed by
 * GET /api/point-system/admin/transactions with `?offset=&limit=&user=&reason=&from=&to=`.
 *
 * Filterable by:
 *   - user   : numeric id or username (resolved server-side)
 *   - reason : exact reason code (e.g. "post.posted", "pointSystem.manual")
 *   - from   : inclusive Y-m-d lower bound on created_at
 *   - to     : inclusive Y-m-d upper bound on created_at
 */
export default class TransactionsPanel extends Component {
  loading = true;
  transactions: any[] = [];
  total = 0;
  offset = 0;

  userFilter = '';
  reasonFilter = '';
  fromFilter = '';
  toFilter = '';

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  async load() {
    this.loading = true;
    m.redraw();
    try {
      const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
      const params = new URLSearchParams();
      params.set('offset', String(this.offset));
      params.set('limit', String(PAGE_SIZE));
      if (this.userFilter.trim()) params.set('user', this.userFilter.trim());
      if (this.reasonFilter.trim()) params.set('reason', this.reasonFilter.trim());
      if (this.fromFilter.trim()) params.set('from', this.fromFilter.trim());
      if (this.toFilter.trim()) params.set('to', this.toFilter.trim());
      const res: any = await app.request({
        method: 'GET',
        url: `${apiUrl}/point-system/admin/transactions?${params.toString()}`,
      });
      this.transactions = Array.isArray(res?.data) ? res.data : [];
      this.total = Number(res?.meta?.total ?? 0);
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail || 'Failed to load transactions');
      this.transactions = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  resetAndLoad() {
    this.offset = 0;
    this.load();
  }

  /**
   * Export the full filtered ledger to CSV. Reuses the same filters as the
   * paginated view but pulls up to 10k rows in one request and downloads them
   * client-side — no new endpoint, no server-side temp file.
   */
  async exportCsv() {
    try {
      const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
      const params = new URLSearchParams();
      params.set('limit', '10000');
      if (this.userFilter.trim()) params.set('user', this.userFilter.trim());
      if (this.reasonFilter.trim()) params.set('reason', this.reasonFilter.trim());
      if (this.fromFilter.trim()) params.set('from', this.fromFilter.trim());
      if (this.toFilter.trim()) params.set('to', this.toFilter.trim());
      const res: any = await app.request({
        method: 'GET',
        url: `${apiUrl}/point-system/admin/transactions?${params.toString()}`,
      });
      const rows: any[] = Array.isArray(res?.data) ? res.data : [];

      const esc = (v: any) => {
        const s = v == null ? '' : String(v);
        return '"' + s.replace(/"/g, '""') + '"';
      };
      const header = ['ID', 'User', 'Amount', 'Reason', 'Reference', 'Created'];
      const lines = rows.map((r) =>
        [
          r.id,
          r.user ? (r.user.displayName || r.user.username || '') : '',
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
      a.download = 'point-transactions.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail || 'Export failed');
    }
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    const t = (k: string, v?: any) => app.translator.trans('ygpynet-point-system.admin.transactions.' + k, v);
    const totalPages = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const currentPage = Math.floor(this.offset / PAGE_SIZE) + 1;

    return (
      <div className="PointSystemAdmin-section">
        <div className="PointSystemAdmin-section-header">
          <h2>
            <i className="fas fa-receipt" /> {t('title')}
            <span className="PointSystemAdmin-counter">{this.total}</span>
          </h2>
          <p className="helpText">{t('help')}</p>
        </div>

        <div className="PointSystemAdmin-card">
          <div
            className="PointSystemAdmin-card-header"
            style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap;"
          >
            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
              <label className="PointSystemAdmin-filter">
                <span>{t('filter_user')}</span>
                <input
                  className="FormControl"
                  type="text"
                  placeholder={t('filter_user_ph')}
                  value={this.userFilter}
                  oninput={(e: Event) => {
                    this.userFilter = (e.target as HTMLInputElement).value;
                  }}
                  onkeydown={(e: KeyboardEvent) => {
                    if (e.key === 'Enter') this.resetAndLoad();
                  }}
                />
              </label>
              <label className="PointSystemAdmin-filter">
                <span>{t('filter_reason')}</span>
                <input
                  className="FormControl"
                  type="text"
                  placeholder={t('filter_reason_ph')}
                  value={this.reasonFilter}
                  oninput={(e: Event) => {
                    this.reasonFilter = (e.target as HTMLInputElement).value;
                  }}
                  onkeydown={(e: KeyboardEvent) => {
                    if (e.key === 'Enter') this.resetAndLoad();
                  }}
                />
              </label>
              <label className="PointSystemAdmin-filter">
                <span>{t('filter_from')}</span>
                <input
                  className="FormControl"
                  type="date"
                  value={this.fromFilter}
                  oninput={(e: Event) => {
                    this.fromFilter = (e.target as HTMLInputElement).value;
                  }}
                />
              </label>
              <label className="PointSystemAdmin-filter">
                <span>{t('filter_to')}</span>
                <input
                  className="FormControl"
                  type="date"
                  value={this.toFilter}
                  oninput={(e: Event) => {
                    this.toFilter = (e.target as HTMLInputElement).value;
                  }}
                />
              </label>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <Button className="Button" onclick={() => this.resetAndLoad()}>
                <i className="fas fa-filter" /> {t('apply')}
              </Button>
              <Button className="Button" onclick={() => this.load()}>
                <i className="fas fa-sync" /> {t('refresh')}
              </Button>
              <Button className="Button" onclick={() => this.exportCsv()}>
                <i className="fas fa-download" /> {t('export')}
              </Button>
            </div>
          </div>

          {this.transactions.length === 0 ? (
            <p className="PointSystemAdmin-empty">{t('empty')}</p>
          ) : (
            <table className="PointSystemAdmin-table PointSystemAdmin-transactionsTable">
              <thead>
                <tr>
                  <th>{t('col_id')}</th>
                  <th>{t('col_user')}</th>
                  <th>{t('col_amount')}</th>
                  <th>{t('col_reason')}</th>
                  <th>{t('col_reference')}</th>
                  <th>{t('col_created')}</th>
                </tr>
              </thead>
              <tbody>{this.transactions.map((tx) => this.renderRow(tx, t))}</tbody>
            </table>
          )}

          {totalPages > 1 && (
            <div className="PointSystemAdmin-pagination">
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
              <span className="PointSystemAdmin-pageInfo">{t('page_x_of_y', { x: currentPage, y: totalPages })}</span>
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
        </div>
      </div>
    );
  }

  renderRow(tx: any, t: (k: string, v?: any) => any) {
    const amount = Number(tx.amount) || 0;
    const user = tx.user || null;
    const sign = amount >= 0 ? '+' : '';
    const reference = referenceLabel(tx.referenceType, tx.referenceId, app);

    return (
      <tr key={`tx-${tx.id}`} className="PointSystemAdmin-transactionsTable-row">
        <td>
          <code>#{tx.id}</code>
        </td>
        <td>
          {user ? (
            <div className="PointSystemAdmin-tradeParty">
              {user.avatarUrl && <img src={user.avatarUrl} alt="" />}
              <span>
                <strong>{user.displayName || user.username || '—'}</strong>
                <small>@{user.username}</small>
              </span>
            </div>
          ) : (
            <span className="muted">—</span>
          )}
        </td>
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
          {reference && reference !== '—' && tx.referenceUrl ? (
            <Link href={tx.referenceUrl} title={reference}>{reference}</Link>
          ) : (
            <small>{reference}</small>
          )}
        </td>
        <td>
          <small className="muted">{tx.createdAt ? new Date(tx.createdAt).toLocaleString() : '—'}</small>
        </td>
      </tr>
    );
  }
}
