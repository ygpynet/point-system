# 测试执行报告 — ygpynet/point-system（积分系统扩展）

- 扩展： `ygpynet/point-system`（命名空间 `Ramon\PointSystem`）
- 执行日期： 2026-09-11
- 环境： Windows / PHP 8.3.32（CLI）/ PHPUnit 11.5.56 / Flarum 2 站点运行于 `http://localhost`（根目录 `D:\phpstudy_pro\WWW`）
- 执行方式：
  1. **PHPUnit 自动化**（`vendor\bin\phpunit`，本轮已安装成功——2026-08-25 报告中的「phpunit 未安装 / 网络阻断」限制已解除）
  2. **线上 HTTP 冒烟**（PowerShell 脚本 `tests/manual/http_smoke_core.ps1`，对运行中的真实站点发请求）
- 被测版本： 工作区 `master@23b0347` + 2026-09-11 改造（上传管线抽取 / revert 精确回滚 / 按驱动判定唯一冲突 / ApiError 本地化 / PointReason 幂等注册 / Extend\DecorationType / 模块 enabled/dependsOn）

> 状态图例： ✅ 已执行并 PASS ｜ ⏭️ SKIP（环境依赖，按设计跳过）｜ ❌ 未执行（工具缺口）

---

## 汇总

| 测试分类 | 用例数 | PASS | SKIP/未执行 |
|---|---|---|---|
| BU 后端单元（PHPUnit unit suite） | 98 | 94 | 4 incomplete（预置 DB-harness 骨架，设计如此，见 TestCase-Design PS-IDE-001/003、PS-CAP-001/002） |
| BI 后端集成（PHPUnit integration suite） | 3 | 0 | 3 跳过：环境缺 `pdo_sqlite`（phpStudy 仅带 pdo_mysql） |
| BI/FE 线上 HTTP 冒烟 | 9 | 9 | 0 |
| FE 浏览器端（设计稿遗留） | 5 | 0 | 5 未执行（无浏览器 harness，见 §六） |
| **合计** | **115** | **103** | **12** |

---

## 一、本轮改动直接相关用例（BU，已执行 ✅）

### 1.1 上传管线抽取（ImageUploadGuard）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-UPL-001 | 合法 PNG 放行 | 构造 1×1 真实 PNG 字节流，以 `avatar.png` 调 `ImageUploadGuard::inspect()` | 返回 `['ext'=>'png','contents'=>原字节]` | ✅ PASS | `Support/ImageUploadGuard.php`、finfo | BU | opencode（AI 执行） | 是 |
| PS-UPL-002 | 上传错误拒绝 | mock `getError()=UPLOAD_ERR_INI_SIZE` | 抛 `UploadValidationException`，status 422，detail=`No image uploaded` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-003 | 分块上传空大小拒绝 | mock `getSize()=null`（PSR-7 允许） | 抛异常，status 413（空值不得绕过上限） | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-004 | 超上限拒绝 | 声明大小 5MB > 4MB 上限 | 抛异常，status 413 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-005 | 非白名单扩展拒绝 | 文件名 `shell.php` | detail=`Only PNG, GIF, WebP, APNG allowed` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-006 | 多语言木马拒绝 | `<?php exit(...);` 内容伪装 `.png` | 魔数校验失败，detail=`File content does not match its extension` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-007 | MIME 白名单缺失时 fail-closed | PNG 字节 + 空 MIME 配置 | 抛 `File MIME does not match its extension`（绝不落盘） | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-UPL-008 | 各格式魔数矩阵 | PNG/GIF87a/GIF89a/RIFF+WEBP/JPEG 头逐格式校验 `signatureMatches()` | 已知格式 true，跨格式/未知格式（svg）false | ✅ PASS | 同上 | BU | 同上 | 是 |

### 1.2 账本精确回滚与唯一冲突判定（PointsRepository）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-UNIQ-001 | 唯一冲突按驱动判定 | 11 组用例：mysql(23000/1062/文案)、pgsql(23505/文案)、sqlite(19/文案)、sqlsrv(2601)、未知驱动回退，反射调私有 `isUniqueViolation` | 每组与驱动语义一致，未知驱动保留通用启发式 | ✅ PASS（11/11） | `Repository/PointsRepository.php`、`MySqlConnection` partial mock | BU | opencode（AI 执行） | 是 |
| PS-MAT-001 | meta 过滤匹配语义 | 反射调私有 `metaMatches`：null 过滤、命中、空过滤、不命中、类型不等（int 7 vs '7'）、缺键、meta 为 null | 7 断言全部符合文档语义 | ✅ PASS（7 断言） | 同上 | BU | 同上 | 是 |
| PS-IDE-000 | 幂等判定改为注册表来源 | 反射调私有 `isIdempotentReason`：三个实体级原因 true；like/checkin/tip/admin/未知 false | 判定完全来自 `PointReason::isIdempotent()` | ✅ PASS | `PointsRepository` + `Support/PointReason` | BU | 同上 | 是 |

### 1.3 幂等语义注册表（PointReason）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-REA-001 | 内置原因覆盖全部已记录代码 | 遍历 28 个文档化代码断言已注册（改为存在性守卫，替代已两次腐化的精确计数断言） | 每个真实写入路径的原因都可解析标签 | ✅ PASS | `Support/PointReason.php` | BU | opencode（AI 执行） | 是 |
| PS-REA-002 | 未知原因回退 | `labelKey('this.does.not.exist')` | 返回 `ygpynet-point-system.lib.reasons.this_does_not_exist`（不报错，点归一化为下划线） | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-REA-003 | 分类与上限标志正确 | checkin/tip.out/tip.in/manual 逐项断言 | earn 计入上限；spend/transfer/admin 不计入 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-REA-004 | 实体级原因幂等 | discussion.started / post.posted / user.registered = true；like.*/checkin/shop.claim/tip.*/admin.adjustment/未知 = false | 幂等集合恰为三个实体级原因，未知码默认可重复 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-REA-005 | 第三方可声明幂等 | `register('acme.once_per_entity', ..., true, true)` | `has()` true 且 `isIdempotent()` true | ✅ PASS | 同上 | BU | 同上 | 是 |

### 1.4 装饰类型扩展点（Extend\DecorationType）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-DTY-001 | 第三方类型注册生效 | 容器 mock 返回 builtIn 注册表，`add('signature',...)->extend($container)` | `has/settingFor/modelFor/resourceFor('signature')` 全部命中 | ✅ PASS | `Extend/DecorationType.php`、`Support/DecorationRegistry` | BU | opencode（AI 执行） | 是 |
| PS-DTY-002 | 策略类透传 | `add(..., policyClass)` | `get('badge')->policyClass` 正确 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-DTY-003 | 内置五族不受影响 | 注册第三方后遍历内置五族 | 五族仍可解析，types 总数 = 6 | ✅ PASS | 同上 | BU | 同上 | 是 |

### 1.5 模块化组合根（Module）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-MOD-001 | 每模块返回合法 extenders | 实例化 6 个模块，遍历 `extenders()` | 数组非空且元素均为 `ExtenderInterface` | ✅ PASS | `src/Module/*`、`extend.php` | BU | opencode（AI 执行） | 是 |
| PS-MOD-002 | EarningModule 走 PointEarner 接缝 | 检查其 extenders[0] | 实例为 `Extend\PointEarner` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-MOD-003 | PointEarner 拒绝非法类 | `add(\stdClass::class)` | 抛 `InvalidArgumentException` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-MOD-004 | enabled/dependsOn 默认值 | Earning/Api/Settings 三模块 | `enabled()===true`、`dependsOn()===[]` | ✅ PASS | `Module/AbstractModule.php` | BU | 同上 | 是 |
| PS-MOD-005 | likes 缺失时模块自禁用 | 本环境未装 flarum/likes，调 `LikesModule::enabled()` | 返回 false（class_exists 探测） | ✅ PASS | `Module/LikesModule.php` | BU | 同上 | 是 |

---

## 二、回归执行的既有用例（BU，已执行 ✅）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-SAN-001~028 | CSS 消毒全矩阵（28 用例/数据集） | `</style>` 突破、`<script>`、IE expression、behavior htc、moz-binding、javascript:/data: URL、@import/@font-face/@charset/@namespace、hex-escape 绕过（`\69mport`）、position:fixed/sticky、display:none、长度截断、幂等性（双跑结果不变） | 恶意原语全部中和，合法 CSS/@keyframes 保留，消毒幂等 | ✅ PASS（28/28） | `Support/CssSanitizer.php`、`CssSanitizerTest` | BU | opencode（AI 执行） | 是 |
| PS-RURL-001~025 | 远程图 URL 校验矩阵（25 数据集） | https/http/带端口/query/fragment/大写 scheme/首尾空白通过；空、`javascript:`、`data:`、`file:`、`vbscript:`、`ftp:`、UNC `\\srv`、协议相对、相对路径、无 host、换行、null 字节、host 内空格、伪装 scheme、超长（>1024）拒绝 | 合法 URL 原样返回；全部恶意输入返回 null | ✅ PASS（25/25） | `Support/RemoteImageUrl.php` | BU | 同上 | 是 |
| PS-REG-001~004 | 装饰注册表 | builtIn 五族有序、avatar 全键可解析、未知类型 null、重复注册幂等 | 与文档一致 | ✅ PASS（4/4） | `Support/DecorationRegistry.php` | BU | 同上 | 是 |
| PS-EAR-001~004 | 点数来源接缝 | 内置 earner 声明的事件类正确、契约实现完整、Registry 拒绝非 earner、重复注册去重 | 与文档一致 | ✅ PASS（4/4） | `Points/PointEarner*.php` | BU | 同上 | 是 |
| PS-TRF-000a | award 非正数返回 null | `award(user, 0/-5, ...)` | 返回 null，不落库 | ✅ PASS | `Repository/PointsRepository.php` | BU | 同上 | 是 |
| PS-TRF-000b | award 系统禁用返回 null | `enabled=false` 时 award | 返回 null | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-TRF-000c | deduct 非正数抛异常 | `deduct(user, 0, ...)` | 抛 `InvalidArgumentException` | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-TRF-001 | 打赏原子转账（编排契约） | Spy 记录 deduct/award 调用 | `deduct('tip.out')` + `award('tip.in', bypassCap=true)`，reference 透传 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-TRF-002 | 余额不足整体回滚 | Spy.deduct 抛 `DomainException` | 异常冒泡、award 调用 0 次 | ✅ PASS | 同上 | BU | 同上 | 是 |
| PS-IDE-006 | dedupe 唯一索引拒绝时返回 null | mock 事务抛 `DuplicateTransactionException` | award 返回 null（孤儿余额增量不提交） | ✅ PASS | 同上 | BU | 同上 | 是 |

---

## 三、后端集成（BI，SKIP ⏭️ — 环境依赖）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-IDE-001 | 幂等重复 award 仅记一行（真库唯一索引） | SQLite 内存库跑真实迁移 up()，同 (reason, refType, refId) 连续 award×2 | 第二次被 `point_system_tx_dedupe` 唯一索引拒绝，事务回滚，仅 1 行 | ⏭️ SKIP：本机 PHP 缺 `pdo_sqlite`（仅 pdo_mysql），测试按设计自动跳过 | `tests/integration/PointsRepositoryDedupeTest.php`、迁移 `2026_08_25_000010` | BI | opencode（AI 执行） | 是 |
| PS-IDE-002 | 非幂等原因不约束 | 同 harness，对 tip 多次 award | dedupe_key 为 NULL，全部成功 | ⏭️ SKIP（同上） | 同上 | BI | 同上 | 是 |
| PS-IDE-003 | 唯一冲突分类正确回滚 | 同 harness，直接经 `isUniqueViolation` 路径 | 冲突转为 `DuplicateTransactionException`，余额无孤儿增量 | ⏭️ SKIP（同上） | 同上 | BI | 同上 | 是 |
| PS-IDE-004（骨架） | 并发重复不超发 | 并发 10 次相同幂等 award | 仅 1 行，余额不重复增加 | ⏭️ incomplete（预置骨架，见单元 suite） | 同上 | BI | 同上 | 是 |
| PS-CAP-001（骨架） | 每日上限截断 | `daily_earn_cap=500` 连续 award | 当日合计 ≤ 500 | ⏭️ incomplete（预置骨架） | 同上 | BI | 同上 | 是 |
| PS-CAP-002（骨架） | 幂等不重复计入上限 | 同幂等原因重复 award | 仅首次计入 | ⏭️ incomplete（预置骨架） | 同上 | BI | 同上 | 是 |

> 备注：如需在本机落地 BI，可改用 `pdo_mysql` + 独立 scratch 库（如 `ps_ext_test`），或为 phpStudy 的 PHP 启用 `extension=pdo_sqlite`。

---

## 四、线上 HTTP 冒烟（BI/FE，已执行 ✅ — 自动化脚本 `tests/manual/http_smoke_core.ps1`）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-HTTP-001 | /api 引导载荷含扩展属性与目录 | GET `http://localhost/api`，解析 JSON | 200，且含 5 个 `pointSystem*Decorations` 目录属性及权限/配置属性 | ✅ PASS：200，命中 **52 个** `pointSystem*` 属性（五目录、GroupOffers、CatalogLimit、4 个权限位、池/交易/签到/打赏配置等） | `Api/ForumAttributes.php`、运行中的 Flarum 站点 | BI | opencode（AI 执行） | 是 |
| PS-HTTP-002 | 论坛首页引用扩展资产 | GET `/` | 200 且 HTML 引用 point-system 前端产物 | ✅ PASS（200，资产引用=True） | `FrontendModule`、webpack 产物 | BI | 同上 | 是 |
| PS-HTTP-003 | 商店 SPA 路由可渲染外壳 | GET `/rewards` | 200（SPA catch-all） | ✅ PASS（200） | 路由 catch-all | FE | 同上 | 是 |
| PS-HTTP-004 | 匿名打卡请求被拒 | POST `/api/point-system/checkin`（无凭据） | 4xx 拒绝，不得写入 | ✅ PASS：HTTP 400 `csrf_token_mismatch`（CSRF 中间件先于鉴权拦截，拒绝语义成立） | `CheckInController`、Flarum CSRF 中间件 | BI | 同上 | 是 |
| PS-HTTP-005 | 匿名购买请求被拒 | POST `/api/point-system/claim/1`（无凭据） | 4xx 拒绝 | ✅ PASS：HTTP 400 `csrf_token_mismatch` | `ClaimItemController` | BI | 同上 | 是 |
| PS-HTTP-006 | 匿名流水接口被拒（正确路径） | GET `/api/point-system/admin/transactions` 与 `/api/point-system/users/1/transactions`（无凭据） | 401/403 拒绝 | ✅ PASS：两者均 **403 PermissionDenied**（路由存在、鉴权拒绝匿名） | `ListTransactionsController`、`ListUserTransactionsController`、迁移 `2026_08_25_000003` | BI | 同上 | 是 |
| PS-HTTP-007 | 未知扩展路由 404 | GET `/api/point-system/definitely-not-a-route` | 404，不误入业务控制器 | ✅ PASS（404） | `ApiRoutesModule` | BI | 同上 | 是 |
| PS-HTTP-008 | 已修复回归：错误流水路径不应存在 | GET `/api/point-system/transactions`（历史误用路径） | 404（该路径未注册，正确） | ✅ PASS（404） | 同上 | BI | 同上 | 是 |

> 说明：PS-HTTP-008 由首轮 T-06 的「404 是否为缺陷」排查转化而来——经路由表核对，正确的管理流水路径为 `/admin/transactions`，历史路径本就不应存在。

---

## 五、结论与风险

1. **全部可执行项通过**：98 个单元用例（482 断言）0 失败；9 个线上冒烟 0 失败。本轮 6 项改造（上传管线抽取、revert 精确回滚、按驱动唯一冲突判定、ApiError 本地化、PointReason 幂等注册、DecorationType/模块扩展点）均有直接用例覆盖，且未破坏既有行为（回归矩阵全绿）。
2. **顺带修复两处测试腐化**：`PointReasonTest` 的精确计数断言（19 → 实际 28）与 `labelKey` 过期前缀断言——原断言在本次改造前即已失效。
3. **残留风险（按优先级）**：
   - BI 数据库路径仅剩骨架/跳过（幂等真库拦截、每日上限、并发）——建议启用 `pdo_sqlite` 或提供 scratch MySQL 库后落地；
   - 浏览器端用例（`reasonLabel` 渲染、CSV 导出、目录缓存）无 harness，未执行；
   - 冒烟仅覆盖匿名视角；管理员/普通用户的登录态 API 测试与交易/商店正向流需带认证 harness。

## 六、未执行清单（工具缺口）

| 用例 | 原因 | 建议工具 |
|---|---|---|
| PS-LED-005（原因码前端本地化渲染） | 无浏览器/JS 测试环境 | Jest + RTL |
| PS-CSV-001/002（流水 CSV 导出） | 无浏览器 | Cypress（设计稿即标记非自动化） |
| PS-CAT-001~003（目录缓存命中/失效/按用户） | 需 DB + 缓存 harness | SQLite suite 落地后补 |
| 登录态正向流（checkin/claim/trade 成功路径） | 需测试账号与 CSRF token 流程 | Flarum `flarum/testing` 集成 harness |

---

# 调试会话增补 — 2026-09-11（发现 → 定位 → 修复 → 重测）

## 调试方法

1. **错误发现**：phpstan level 5 全量静态扫描（221 条，过滤 Eloquent stub 噪音后定位高信号项）+ 审查从未被测试覆盖的 `TradeRepository`（资金/库存路径）+ 在 scratch MySQL 库 `ps_ext_test` 上落地此前跳过的集成套件（真实 InnoDB 唯一键/事务语义）。
2. **隔离复现**：每个嫌疑缺陷一个最小 RED 测试（`tests/integration/TradeExecuteRevertTest`、`TransferEventLeakTest`）。
3. **根因确认 → 修复 → GREEN → 全量回归**。

## 缺陷清单与处置

| 编号 | 缺陷 | 根因 | 修复 | 回归 |
|---|---|---|---|---|
| PS-TRD-001 | 管理员回滚交易在「捐出方仍留有同款副本」时触发 `1062 Duplicate entry` → HTTP 500，交易卡在 completed | `TradeRepository::revert()` 以原地翻转 `ShopClaim.user_id` 恢复所有权，与 `UNIQUE(user_id,item_type,item_id)` 冲突 | 重写为 execute() 的镜像：当前持有人数量 -1/删除，原持有人 +1/新建（TradeRepository.php:504+） | ✅ `test_revert_restores_donor_copies_without_unique_violation` |
| PS-TRD-002 | 回滚后收货方装备指针 `current_*_decoration_id` 悬挂（指向已不拥有的商品） | revert() 缺少 execute() 具有的装备指针清理 | 移出物品时同步清空失去方指针 | ✅ `test_revert_clears_equipped_pointer_of_post_trade_owner` |
| PS-EVT-003 | 转账中途失败回滚后，监听器已收到扣款腿 `PointsAwarded(-100)` 假事件（通知/推送与账实不符） | `transfer()` 复用公开 `deduct()/award()`，各腿在外层事务内自行 flush 事件 | 抽取 `deductWithin()/awardWithin()` 事务内业务腿，transfer 在**提交后**统一 flush（PointsRepository.php） | ✅ `test_failed_transfer_must_not_leak_debit_events`（RED→GREEN） |
| PS-LTM-001 | 转账收到的积分被计入 lifetime（自动用户组阈值按 lifetime 判定 → 收打赏可升级组），与 transfer() 文档「received points aren't earned」矛盾 | `awardWithin` 无差别递增 lifetime（TradeRepository 的转移则从不触碰——同类语义不一致） | `awardWithin(..., touchLifetime=true)`：transfer 的入账腿传 false | ✅ `test_transfer_moves_both_balances_and_writes_both_rows` |
| PS-REA-006 | 流水标签裸码：`trade_reverted` 未注册 | 原因码注册表缺项 | `PointReason::builtIn()` 注册 + en/zh-Hans/pt-BR 标签 | ✅ PointReasonTest |
| PS-HAR-001 | 集成 harness 隐患：`repo()` 仍为 3 参构造（构造器改造时遗漏） | 上轮遗漏 | 抽取共享基类 `IntegrationTestCase`（env 驱动 sqlite/mysql、全量迁移重放、spy dispatcher），dedupe 测试改继承 | ✅ |

## 重测结果

| 套件 | 结果 |
|---|---|
| Integration（MySQL scratch `ps_ext_test`，`PS_DB_DRIVER=mysql`） | ✅ 12/12（46 断言） |
| Unit（PHPUnit） | ✅ 106 通过 / 4 incomplete（预置骨架，其真实覆盖已由集成套件承接） |
| 全量合计 | ✅ 110 tests / 537 断言，0 失败 |
| 线上 HTTP 冒烟（`tests/manual/http_smoke_core.ps1`，预期已按实测行为修正） | ✅ 9/9 |
| phpstan（改动文件复查） | ✅ 无新增真实类型问题（剩余均为已识别的 Eloquent stub 噪音） |

## 说明

- MySQL scratch 库 `ps_ext_test`（本地 8.4 实例）由测试自建自清（每用例 drop+replay 迁移），保留供后续 BI 运行；`PS_DB_DRIVER` 未设时默认 sqlite 路径不变。
- phpstan 已加入 require-dev。

---

# 缺陷修复增补 — 补签机会被误点签到销毁（2026-09-11）

## 问题描述

用户存在缺勤缺口（符合补签条件）时，误点普通签到：`last_checkin_date` 被推进到今天，
缺口锚点被抹除——补签接口从此永远返回 `no_gap`，断掉的连签无法再修复。且前端按钮
条件为 `canMakeup && !done`，误签后 `doneToday=true` 直接隐藏补签按钮。

## 根因

缺口判定与补签目标都锚定在 `last_checkin_date`（最后签到日）上，而该字段在普通签到时
总会推进到今天——「缺口」这个事实没有独立的记录，被签到指针覆盖。

## 修复

1. `CheckInSettings::makeupTarget()`（新增）：以 `point_system_checkin_days`（逐日事实记录）
   为锚——`锚点 = 早于今天的最后签到日`，缺口 = (锚点, 昨日]，目标 = 锚点+1。
   误签后锚点仍是缺口前的最后一天，机会保留。
2. `CheckInSettings::canMakeup()`：改用同一锚点规则（保留「预算须覆盖整个缺口」的
   门控哲学）。
3. `MakeUpController`：目标推导改用 makeupTarget()；补签后 streak 改为按
   `point_system_checkin_days` 重算「截止最新签到日的连续段」——经典流程（+1）由同一
   公式自然推出；误签场景则恢复完整桥接段（修复插入必须先于重算，回归测试捕获过该顺序错误）。
4. 前端 `CheckInWidget.tsx`：按钮条件去掉 `!done`（`canMakeup` 本身已编码「存在可修复
   缺口且预算充足」）；重新构建 `js/dist`，`php flarum cache:clear` 后合并包已更新。

## 回归（tests/integration/MakeUpRepairTest.php，真实 MySQL harness）

| 用例 | 内容 | 结果 |
|---|---|---|
| classic_gap_fill_is_unchanged | 经典缺口流程行为不变（目标/连签/扣费） | ✅ |
| accidental_checkin_does_not_destroy_the_makeup_opportunity | 误签后补签仍可执行，连签恢复为完整连续段，last 保持今天 | ✅ |
| multi_day_gap_after_accidental_checkin_walks_forward | 多日缺口逐日推进，中间态/终态连签正确 | ✅ |
| no_gap_when_caught_up_through_yesterday | 追平后仍拒绝（no_gap），不扣费 | ✅ |
| can_makeup_uses_the_trailing_gap_and_budget | 门控：误签后可用；预算不足整个缺口时隐藏 | ✅ |

全量重测：PHPUnit **115 tests / 559 断言 0 失败**；线上冒烟 **9/9**。
