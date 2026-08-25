// @ts-nocheck
import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Avatar from 'flarum/common/components/Avatar';
import type Post from 'flarum/common/models/Post';

export default class PostTipListModal extends Modal {
  className() {
    return 'PostTipListModal Modal--small';
  }

  title() {
    return app.translator.trans('ygpynet-point-system.forum.tip_list.title');
  }

  content() {
    const icon = app.forum.attribute('pointSystem.currency_icon') || 'fas fa-coins';
    const tips = (this.attrs.post.attribute('pointSystemTips') as any[]) || [];

    if (tips.length === 0) {
      return (
        <div className="Modal-body">
          <p className="PostTipListModal-empty">{app.translator.trans('ygpynet-point-system.forum.tip_list.empty')}</p>
        </div>
      );
    }

    const total = tips.reduce((sum, t) => sum + (Number(t.amount) || 0), 0);

    return (
      <div className="Modal-body">
        <div className="PostTipListModal-summary">
          <i className={icon} aria-hidden="true" />
          <span>
            {app.translator.trans('ygpynet-point-system.forum.tip_list.total', {
              total: total.toLocaleString(),
              count: tips.length,
            })}
          </span>
        </div>

        <ul className="PostTipListModal-list">
          {tips.map((t) => {
            // Prefer a fully-loaded user from the store (already on this page).
            // Otherwise synthesize one from the serialized sender data so the
            // avatar + profile link render even for tippers not present here.
            const existing = app.store.getById('users', String(t.senderId));
            const user =
              existing ||
              app.store.pushPayload({
                data: {
                  type: 'users',
                  id: String(t.senderId),
                  attributes: {
                    username: t.senderUsername,
                    displayName: t.senderName,
                    avatarUrl: t.senderAvatarUrl,
                  },
                },
              });

            const username =
              t.senderUsername ||
              (existing && typeof existing.username === 'function' ? existing.username() : null);

            const name = <span className="PostTipListModal-name">{t.senderName}</span>;

            return (
              <li className="PostTipListModal-item" key={t.senderId}>
                <Avatar user={user} />
                {username ? (
                  <a href={app.route('user', { username })} className="PostTipListModal-nameLink">
                    {name}
                  </a>
                ) : (
                  name
                )}
                <span className="PostTipListModal-amount">
                  <i className={icon} aria-hidden="true" />
                  {Number(t.amount).toLocaleString()}
                </span>
              </li>
            );
          })}
        </ul>
      </div>
    );
  }
}
