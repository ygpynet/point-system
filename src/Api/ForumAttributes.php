<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Ramon\PointSystem\FeatureGate;
use Ramon\PointSystem\Model\AvatarDecoration;
use Ramon\PointSystem\Model\CoverDecoration;
use Ramon\PointSystem\Model\GroupOffer;
use Ramon\PointSystem\Model\NameDecoration;
use Ramon\PointSystem\Model\PostHighlightDecoration;
use Ramon\PointSystem\Model\ShopClaim;
use Ramon\PointSystem\Model\TitleDecoration;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Support\CssSanitizer;
use Ramon\PointSystem\Support\ItemAvailability;
use Ramon\PointSystem\Support\SubmissionScope;

/**
 * Adds the catalog of enabled decorations to the forum payload so they can be
 * rendered on any page (post stream, user card, etc.) without an extra
 * round-trip — `injectNameDecorationStyles` (js/src/forum/index.tsx) precisa do
 * par slug/customCss globalmente, não só na rota da loja.
 *
 * Cada catálogo é limitado a {@see self::CATALOG_LIMIT}. A justificativa
 * original ("o catálogo é pequeno, curado por admin") deixou de valer com
 * submissões de usuário; o teto mantém o payload previsível e o frontend
 * anuncia o corte via `pointSystemCatalogLimit`.
 *
 * Decoration catalogs use {@see ItemAvailability::applyShopOrOwnedScope}: the
 * public shop sees only enabled / listed / in-window / unrestricted items, but
 * a user that already OWNS an item keeps seeing it even after the admin
 * disables, unlists, or lets it expire. Each item also ships an `isAvailable`
 * boolean so the shop UI can filter the "buyable" grid down to active items
 * while the "My decorations" page still shows everything owned.
 *
 * Group offers don't have a ShopClaim equivalent (membership lives in the
 * group_user pivot) so they keep the original is_enabled + shop-scope path.
 *
 * Note on customCss fields: although these strings are sanitized on WRITE
 * inside each decoration resource, we re-run {@see CssSanitizer::sanitize}
 * on EMIT here as defense-in-depth (CLAUDE.md §21). Rationale: admin-account
 * compromise is part of the threat model, and rows that pre-date the write
 * sanitizer (or that come from a direct DB edit) are normalized to the same
 * allowlist before being serialized into the forum payload.
 */
class ForumAttributes
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected FeatureGate $features,
    ) {}

    /**
     * Teto de itens por catálogo no payload do fórum.
     *
     * Estes seis arrays viajam em TODA página, para TODO visitante — inclusive
     * guest. O docblock da classe justificava a carga total com "o catálogo é
     * pequeno (curado por admin)", premissa que caiu quando `user_submissions`
     * passou a deixar usuários criarem decorações: sem teto o payload cresce
     * junto com a fila de submissões aprovadas.
     *
     * 200 é o mesmo teto máximo dos endpoints JSON:API (`paginate(100, 200)`),
     * então o caminho de bootstrap e o paginado passam a concordar. O frontend
     * compara `length >= pointSystemCatalogLimit` para avisar que a lista veio
     * cortada — truncar em silêncio é o que §40.6 proíbe.
     */
    public const CATALOG_LIMIT = 200;

    /**
     * Cache estático da presença da coluna `creator_id` por tabela. Estado
     * de schema NÃO depende do ator e só muda quando o admin roda migrate
     * (que reinicia o worker em qualquer host minimamente competente), então
     * cachear cross-request elimina 5 lookups `INFORMATION_SCHEMA` por
     * page-load. Mesmo padrão de {@see SubmissionScope::$columnCache}.
     *
     * @var array<string, bool>
     */
    private static array $hasCreatorColumnCache = [];

    /**
     * Cache por-ator dos IDs possuídos agrupados por tipo. Carregado em UMA
     * query (`SELECT item_type, item_id FROM shop_claims WHERE user_id = ?`)
     * na primeira leitura e reusado pelos cinco fields de decoração. Sem
     * isto a página de bootstrap dispara 5 lookups `ShopClaim` redundantes.
     * Chave inclui `0` para guest (sempre vazio).
     *
     * @var array<int, array<string, int[]>>
     */
    private array $ownedByActor = [];

    public function __invoke(): array
    {
        // The submission columns (`status`, `creator_id`) ship with the
        // 2026_05_16_000004 migration. If an admin upgrades the code BEFORE
        // running `php flarum migrate`, accessing those properties on a
        // pre-migration model still works (Eloquent treats them as null),
        // but the SubmissionScope SQL filter and the `with('creator')` load
        // would crash. SubmissionScope already detects this and self-skips;
        // we mirror that check here so the serializer doesn't ship bogus
        // creator data when the columns aren't there yet.
        $serializeAvailability = static function ($d, $actor): array {
            $hasCreator = isset($d->attributes['creator_id']) || array_key_exists('creator_id', $d->getAttributes());
            $creator = $hasCreator && $d->creator_id ? $d->creator : null;
            return [
                'isEnabled'        => (bool) ($d->is_enabled ?? true),
                'isAvailable'      => ItemAvailability::reasonNotClaimable($d, $actor) === null,
                'maxClaims'        => $d->max_claims !== null ? (int) $d->max_claims : null,
                'claimCount'       => (int) ($d->claim_count ?? 0),
                'availableFrom'    => optional($d->available_from)?->toIso8601String(),
                'availableUntil'   => optional($d->available_until)?->toIso8601String(),
                'isListed'         => (bool) ($d->is_listed ?? true),
                'allowedGroupIds'  => ItemAvailability::allowedGroupIds($d) ?? [],
                'status'           => (string) ($d->status ?? 'approved'),
                'creatorId'        => $hasCreator && $d->creator_id !== null ? (int) $d->creator_id : null,
                'creatorUsername'  => $creator ? (string) $creator->username : null,
                'creatorDisplayName' => $creator ? (string) ($creator->display_name ?? $creator->username) : null,
                'creatorAvatarUrl' => $creator && $creator->avatar_url ? (string) $creator->avatar_url : null,
            ];
        };

        $scopeFor = function (Builder $q, Context $context, string $itemType): Builder {
            $actor = $context->getActor();
            $model = $q->getModel();
            $table = $model->getTable();
            // Cache cross-request — schema só muda em migrate, e nesse
            // momento o worker reinicia (ver docblock de $hasCreatorColumnCache).
            if (! array_key_exists($table, self::$hasCreatorColumnCache)) {
                try {
                    self::$hasCreatorColumnCache[$table] = $model->getConnection()
                        ->getSchemaBuilder()
                        ->hasColumn($table, 'creator_id');
                } catch (\Throwable) {
                    self::$hasCreatorColumnCache[$table] = false;
                }
            }
            if (self::$hasCreatorColumnCache[$table]) {
                $q->with('creator');
            }
            // Owned IDs vêm pré-carregados (1 query agrupada por actor) em vez
            // de 5 SELECTs ShopClaim por page-load.
            $ownedIds = $this->ownedIdsForActor($actor)[$itemType] ?? [];
            ItemAvailability::applyShopOrOwnedScope($q, $actor, $itemType, $ownedIds);
            SubmissionScope::apply($q, $actor);
            return $q;
        };

        // Group offers keep the older shop-scope path: there's no per-user
        // "ownership" of an offer (the user is in the group or not), so
        // disabling an offer should be safe to do — current members keep
        // their group membership independently of the offer row's state.
        $scopeForOffers = function (Builder $q, Context $context): Builder {
            $q->where('is_enabled', true);
            ItemAvailability::applyShopScope($q, $context->getActor());
            return $q;
        };

        return [
            Schema\Arr::make('pointSystemAvatarDecorations')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeFor) {
                    if (! $this->features->isEnabled(ShopClaim::TYPE_AVATAR)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey(ShopClaim::TYPE_AVATAR, $actor),
                        fn () => $scopeFor(AvatarDecoration::query(), $context, ShopClaim::TYPE_AVATAR)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (AvatarDecoration $d) => array_merge([
                                'id' => $d->id,
                                'name' => $d->name,
                                'description' => $d->description,
                                'imagePath' => $d->image_path,
                                'imageUrl' => $d->image_url,
                                'isAnimated' => (bool) $d->is_animated,
                                'price' => (int) $d->price,
                            ], $serializeAvailability($d, $actor)))
                            ->toArray()
                    );
                }),

            Schema\Arr::make('pointSystemNameDecorations')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeFor) {
                    if (! $this->features->isEnabled(ShopClaim::TYPE_NAME)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey(ShopClaim::TYPE_NAME, $actor),
                        fn () => $scopeFor(NameDecoration::query(), $context, ShopClaim::TYPE_NAME)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (NameDecoration $d) => array_merge([
                                'id' => $d->id,
                                'name' => $d->name,
                                'slug' => $d->slug,
                                'description' => $d->description,
                                'preset' => $d->preset,
                                'customCss' => CssSanitizer::sanitize($d->custom_css),
                                'price' => (int) $d->price,
                            ], $serializeAvailability($d, $actor)))
                            ->toArray()
                    );
                }),

            Schema\Arr::make('pointSystemCoverDecorations')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeFor) {
                    if (! $this->features->isEnabled(ShopClaim::TYPE_COVER)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey(ShopClaim::TYPE_COVER, $actor),
                        fn () => $scopeFor(CoverDecoration::query(), $context, ShopClaim::TYPE_COVER)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (CoverDecoration $d) => array_merge([
                                'id' => $d->id,
                                'name' => $d->name,
                                'description' => $d->description,
                                'imagePath' => $d->image_path,
                                'imageUrl' => $d->image_url,
                                'isAnimated' => (bool) $d->is_animated,
                                'price' => (int) $d->price,
                            ], $serializeAvailability($d, $actor)))
                            ->toArray()
                    );
                }),

            Schema\Arr::make('pointSystemTitleDecorations')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeFor) {
                    if (! $this->features->isEnabled(ShopClaim::TYPE_TITLE)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey(ShopClaim::TYPE_TITLE, $actor),
                        fn () => $scopeFor(TitleDecoration::query(), $context, ShopClaim::TYPE_TITLE)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (TitleDecoration $d) => array_merge([
                                'id' => $d->id,
                                'name' => $d->name,
                                'slug' => $d->slug,
                                'description' => $d->description,
                                'titleText' => $d->title_text,
                                'color' => $d->color,
                                'customCss' => CssSanitizer::sanitize($d->custom_css),
                                'price' => (int) $d->price,
                            ], $serializeAvailability($d, $actor)))
                            ->toArray()
                    );
                }),

            Schema\Arr::make('pointSystemPostHighlightDecorations')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeFor) {
                    if (! $this->features->isEnabled(ShopClaim::TYPE_POST_HL)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey(ShopClaim::TYPE_POST_HL, $actor),
                        fn () => $scopeFor(PostHighlightDecoration::query(), $context, ShopClaim::TYPE_POST_HL)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (PostHighlightDecoration $d) => array_merge([
                                'id' => $d->id,
                                'name' => $d->name,
                                'slug' => $d->slug,
                                'description' => $d->description,
                                'preset' => $d->preset,
                                'customCss' => CssSanitizer::sanitize($d->custom_css),
                                'price' => (int) $d->price,
                            ], $serializeAvailability($d, $actor)))
                            ->toArray()
                    );
                }),

            // Group offers shown on the Rewards page. Each offer can be
            // unlocked via auto-attach (lifetime threshold), explicit purchase
            // (balance deduction), or both — the UI uses the flags to render
            // the right CTA per card. Returns empty when the feature is off.
            Schema\Arr::make('pointSystemGroupOffers')
                ->get(function ($_, Context $context) use ($serializeAvailability, $scopeForOffers) {
                    if (! (bool) $this->settings->get('point-system.auto_group_enabled', true)) {
                        return [];
                    }
                    $actor = $context->getActor();
                    return $this->rememberCatalog(
                        $this->catalogKey('group_offers', $actor),
                        fn () => $scopeForOffers(GroupOffer::query()->with('group'), $context)
                            ->orderBy('points_required')
                            ->limit(self::CATALOG_LIMIT)
                            ->get()
                            ->map(fn (GroupOffer $o) => array_merge([
                                'id' => $o->id,
                                'groupId' => $o->group_id,
                                'groupName' => optional($o->group)->name_plural ?: optional($o->group)->name_singular,
                                'groupColor' => optional($o->group)->color,
                                'groupIcon' => optional($o->group)->icon,
                                'pointsRequired' => (int) $o->points_required,
                                'price' => (int) $o->price,
                                'isAuto' => (bool) $o->is_auto,
                                'isPurchasable' => (bool) $o->is_purchasable,
                            ], $serializeAvailability($o, $actor)))
                            ->toArray()
                    );
                }),

            // Teto aplicado aos seis catálogos acima. O frontend compara
            // `items.length >= este valor` para avisar que a lista veio
            // cortada — sem isto o corte seria silencioso (§40.6).
            Schema\Integer::make('pointSystemCatalogLimit')
                ->get(fn () => self::CATALOG_LIMIT),

            // Per-user permissions exposed to the frontend so we can gate the
            // nav entry, the Rewards page itself, and the claim button.
            Schema\Boolean::make('pointSystemCanViewShop')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.viewShop')),

            Schema\Boolean::make('pointSystemCanClaim')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.claim')),

            Schema\Boolean::make('pointSystemCanManage')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.manage')),

            // Tip roster ("打赏名单") — gates the per-post "view tippers" entry
            // in the post controls dropdown. Mirrors the other per-user
            // permission booleans above so the frontend can gate the button
            // without ever touching a (non-existent) `user.can()` helper.
            Schema\Boolean::make('pointSystemCanViewTipList')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.viewTipList')),

            // Points-ledger audit — lets the frontend gate the "积分流水" tab on
            // OTHER users' profiles (the owner always sees their own; the
            // server enforces the same permission per-request in
            // ListUserTransactionsController, so this boolean is UX-only).
            Schema\Boolean::make('pointSystemCanViewTransactions')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.viewTransactions')),

            // Points-pool visibility — gates BOTH the sidebar widget (frontend
            // checks this boolean) and the pool aggregate below (groups without
            // the permission get enabled=false / total=0, so the economy stat
            // never reaches them over the wire). The permission itself is
            // seeded for Members by migration 2026_09_11_000001 and manageable
            // from the Permissions page.
            Schema\Boolean::make('pointSystemCanViewPool')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.viewPool')),

            // ── Points pool (积分池) ────────────────────────────────────────
            // Master toggle + the ISSUED aggregate the widget renders (Σ of
            // every member's current balance — "how many points users hold
            // right now", however they were earned). Computed once per TTL
            // and shared by ALL visitors (public stat); the cache key is
            // intentionally actor-agnostic. 60s staleness is imperceptible
            // for a display stat and keeps the aggregate off the hot path
            // (tips/check-ins mutate balances constantly). The frontend
            // derives remaining = budget − issued; burns (giveaway entries,
            // shop claims…) shrink the issued sum and thus refill the pool
            // automatically.
            Schema\Boolean::make('pointSystemPoolEnabled')
                ->get(function ($_, Context $context) {
                    if (! $context->getActor()->hasPermission('pointSystem.viewPool')) {
                        return false;
                    }

                    return (bool) $this->settings->get('point-system.pool_enabled', true);
                }),

            Schema\Integer::make('pointSystemPoolTotal')
                ->get(fn ($_, Context $context) => $context->getActor()->hasPermission('pointSystem.viewPool')
                    ? ($this->poolEnabled() ? $this->poolIssued() : 0)
                    : 0),

            // Trade subsystem — exposes both the master toggle (so the UI
            // can hide the "Trade" button forum-wide) and the per-actor
            // permission (so the UI hides the button for users in groups
            // the admin hasn't authorized). The frontend must check BOTH:
            //   pointSystemTradeEnabled && pointSystemCanTrade
            Schema\Boolean::make('pointSystemTradeEnabled')
                ->get(fn () => $this->features->isTradeEnabled()),

            Schema\Boolean::make('pointSystemCanTrade')
                ->get(fn ($_, Context $context) =>
                    $this->features->isTradeEnabled()
                    && $context->getActor()->hasPermission('pointSystem.trade')
                ),

            // User-submission feature: master toggle exposure. The "Submit
            // decoration" CTA on the forum reads this; the JSON:API Create
            // endpoint enforces it server-side too. Authenticated-only —
            // guests never see the submit option.
            Schema\Boolean::make('pointSystemUserSubmissionsEnabled')
                ->get(fn () => $this->features->isUserSubmissionsEnabled()),
        ];
    }

    /**
     * Cache de catálogo: os seis catálogos de decorações viajam em TODA página
     * para TODO visitante (inclusive guest). Sem cache, são 5–6 queries por
     * page-load; com o cache de 60s o custo cai para quase nulo na maior parte
     * do tráfego. A chave inclui o actor (cada usuário vê seu próprio
     * `isAvailable`/possessões) e uma assinatura das toggles de feature — virar
     * qualquer feature (ou aprovar uma submissão que dependa delas) invalida
     * todos os caches de catálogo de uma vez. Janela de obsolescência de 60s é
     * aceitável para um catálogo de loja; o "My decorations" reflete posse via
     * ShopClaim独立 e não depende só deste payload.
     */
    private const CATALOG_TTL = 60;

    private function catalogSignature(): string
    {
        $parts = [
            (string) $this->settings->get('point-system.auto_group_enabled', '1'),
            (string) $this->features->isEnabled(ShopClaim::TYPE_AVATAR),
            (string) $this->features->isEnabled(ShopClaim::TYPE_NAME),
            (string) $this->features->isEnabled(ShopClaim::TYPE_COVER),
            (string) $this->features->isEnabled(ShopClaim::TYPE_TITLE),
            (string) $this->features->isEnabled(ShopClaim::TYPE_POST_HL),
            (string) $this->features->isTradeEnabled(),
            (string) $this->features->isUserSubmissionsEnabled(),
        ];

        return md5(implode('|', $parts));
    }

    private function catalogKey(string $type, $actor): string
    {
        $actorId = $actor && method_exists($actor, 'getKey') ? (int) ($actor->getKey() ?? 0) : 0;

        return "point-system.catalog.{$type}.{$actorId}." . $this->catalogSignature();
    }

    /**
     * Resolve do cache (TTL 60s) ou executa o loader e armazena. Qualquer falha
     * de cache (driver ausente, exceção) degrada graciosamente para o loader sem
     * quebrar o render do fórum.
     */
    private function rememberCatalog(string $key, callable $loader): array
    {
        try {
            $cache = app(\Illuminate\Contracts\Cache\Repository::class);
            $hit = $cache->get($key);
            if (is_array($hit)) {
                return $hit;
            }
            $value = $loader();
            $cache->put($key, $value, self::CATALOG_TTL);

            return $value;
        } catch (\Throwable) {
            return $loader();
        }
    }

    private function poolEnabled(): bool
    {
        return (bool) $this->settings->get('point-system.pool_enabled', true);
    }

    /**
     * Points ISSUED to the community: Σ of every member's current balance.
     * rememberCatalog() is typed `: array` (catalogs), so the scalar cache
     * lives inline here. Any cache failure degrades to a live SUM, same as
     * the catalogs.
     */
    private function poolIssued(): int
    {
        try {
            $cache = app(\Illuminate\Contracts\Cache\Repository::class);
            $hit = $cache->get('point-system.pool.issued');
            if ($hit !== null) {
                return (int) $hit;
            }
            $issued = (int) UserPoints::query()
                ->selectRaw('COALESCE(SUM(balance), 0) AS t')
                ->value('t');
            $cache->put('point-system.pool.issued', $issued, self::CATALOG_TTL);

            return $issued;
        } catch (\Throwable) {
            return (int) UserPoints::query()
                ->selectRaw('COALESCE(SUM(balance), 0) AS t')
                ->value('t');
        }
    }

    /**
     * Devolve o mapa `[itemType => int[]]` de IDs possuídos pelo ator,
     * carregado em UMA query e cacheado para o resto do request. Guests
     * obtêm `[]` cacheado em chave 0 — evita repetir o no-op.
     *
     * @return array<string, int[]>
     */
    private function ownedIdsForActor($actor): array
    {
        $key = $actor && method_exists($actor, 'getKey') ? (int) ($actor->getKey() ?? 0) : 0;
        return $this->ownedByActor[$key] ??= ItemAvailability::ownedIdsByType(
            $actor instanceof \Flarum\User\User ? $actor : null,
        );
    }
}
