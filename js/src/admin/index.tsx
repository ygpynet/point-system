// @ts-nocheck
import app from 'flarum/admin/app';
import PointSystemSettingsPage from './components/PointSystemSettingsPage';
import AvatarDecoration from './models/AvatarDecoration';
import NameDecoration from './models/NameDecoration';
import CoverDecoration from './models/CoverDecoration';
import TitleDecoration from './models/TitleDecoration';
import PostHighlightDecoration from './models/PostHighlightDecoration';
import GroupOffer from './models/GroupOffer';
import registerWidget from '../common/registerWidget';

app.initializers.add('ygpynet/point-system', () => {
  // Register custom JSON:API resource types with the store so app.store.find()
  // can deserialize responses. Type keys MUST match each resource's PHP type().
  app.store.models['point-system-avatar-decorations'] = AvatarDecoration;
  app.store.models['point-system-name-decorations'] = NameDecoration;
  app.store.models['point-system-cover-decorations'] = CoverDecoration;
  app.store.models['point-system-title-decorations'] = TitleDecoration;
  app.store.models['point-system-post-highlight-decorations'] = PostHighlightDecoration;
  app.store.models['point-system-group-offers'] = GroupOffer;

  app.registry
    .for('ygpynet-point-system')
    .registerPage(PointSystemSettingsPage)
    .registerPermission(
      {
        icon: 'fas fa-coins',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.view_shop'),
        permission: 'pointSystem.viewShop',
        // The shop is a public catalog — nothing here is per-user PII (the
        // ForumAttributes payload already gates the catalog to enabled/listed
        // items and the balance pill is `{user && ...}`-only). Let admins open
        // it to Guests so logged-out visitors can browse the rewards before
        // signing up. `pointSystemCanViewShop` reads `hasPermission` against
        // the actor, so a Guest grant Just Works on the backend with no further
        // change. Claiming still requires `pointSystem.claim` (members only).
        allowGuest: true,
      },
      'view'
    )
    .registerPermission(
      {
        icon: 'fas fa-eye',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.view_others'),
        permission: 'pointSystem.viewOthers',
        // Other users' point balances are PII-adjacent; require an
        // explicit Members grant. Admins who want public scoreboards can
        // still add the permission to Guest via the per-grant UI.
        allowGuest: false,
      },
      'view'
    )
    .registerPermission(
      {
        icon: 'fas fa-piggy-bank',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.view_pool'),
        permission: 'pointSystem.viewPool',
        // The pool is a community display stat (issued total + remaining
        // budget, no PII) — seeded for Members only by migration
        // 2026_09_11_000001; logged-out visitors don't see it unless an
        // admin grants Guest here. Revoking a group hides the sidebar
        // widget AND stops the pool aggregate from being serialized to it
        // (ForumAttributes).
        allowGuest: true,
      },
      'view'
    )
    .registerPermission(
      {
        icon: 'fas fa-shopping-cart',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.claim'),
        permission: 'pointSystem.claim',
      },
      'reply'
    )
    .registerPermission(
      {
        icon: 'fas fa-cog',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.manage'),
        permission: 'pointSystem.manage',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-handshake',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.trade'),
        permission: 'pointSystem.trade',
      },
      'reply'
    )
    .registerPermission(
      {
        icon: 'fas fa-list',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.view_tip_list'),
        permission: 'pointSystem.viewTipList',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-receipt',
        label: app.translator.trans('ygpynet-point-system.admin.permissions.view_transactions'),
        permission: 'pointSystem.viewTransactions',
      },
      'moderate'
    );

  // Required by fof/forum-widgets-core: widgets must be registered in both
  // frontends even though only the forum renders them. Kept LAST so a failure
  // here can never blank the extension page / permissions above.
  registerWidget(app);
});
