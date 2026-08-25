# 测试用例设计 — ygpinepoint-system（积分系统扩展）

- 扩展： `ygpynet/point-system`（命名空间 `Ramon\PointSystem`）
- 文档版本： 2026-08-25
- 范围： 覆盖积分流水记录、幂等性、原子转账/打赏、原因注册表、事件、CSV 导出、目录缓存、每日上限。
- 说明： 本表为**设计稿**。所有用例的「实际结果」初始为「待执行」，「负责人」初始为「待分配」，落地时替换。

## 测试分类约定

| 代号 | 含义 | 技术栈 |
|---|---|---|
| BU | 后端单元测试 | PHPUnit，mock 数据库/容器，不启 Flarum |
| BI | 后端集成测试 | PHPUnit + SQLite 内存库，真实跑仓库逻辑 |
| FE | 前端测试 | Jest + React Testing Library / Cypress E2E |

## 字段说明

- 测试用例编号： 全局唯一，格式 `PS-<域>-<序号>`
- 测试用例描述： 一句话说明验证点
- 测试步骤： 可复现的操作序列
- 预期结果： 系统应表现的行为
- 实际结果： 执行后填写（默认「待执行」）
- 相关依赖： 涉及的类/接口/迁移/配置
- 测试分类： BU / BI / FE
- 负责人： 默认「待分配」
- 是否是自动化测试： 是 / 否

---

## 一、积分流水记录（Ledger）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-LED-001 | 管理员查看流水列表 | 1. 以拥有 `pointSystem.viewTransactions` 的 Admin 登录 2. 访问 `GET /api/points/transactions` | 返回按时间倒序的流水数组，含 `user/username/reason/amount/balance/createdAt` | 待执行 | `ListTransactionsController`、`TransactionSerializer`、权限迁移 `2026_08_25_000003` | BI | 待分配 | 是 |
| PS-LED-002 | 无权限用户被拒 | 1. 普通用户访问 admin 流水接口 | 返回 403，无数据泄露 | 待执行 | 同上 | BI | 待分配 | 是 |
| PS-LED-003 | 用户查看自己的流水 | 1. 用户 A 登录 2. `GET /api/users/{A}/point-transactions` | 仅返回 A 的流水，不能看他人 | 待执行 | `ListUserTransactionsController` | BI | 待分配 | 是 |
| PS-LED-004 | 流水过滤（用户/原因/日期） | 1. 传 `filter[reason]=daily_checkin` 与日期区间 | 结果集严格匹配过滤条件 | 待执行 | 仓库 query 构造 | BI | 待分配 | 是 |
| PS-LED-005 | 原因代码本地化为标签 | 1. 渲染流水行 2. 校验 `reasonLabel('daily_checkin')` | 显示「每日签到」而非原始代码 | 待执行 | `reasonLabel.ts`、`locale/zh-Hans.yml` 与 `locale/en.yml` 的 `reasons.*` | FE | 待分配 | 是 |

## 二、幂等性（dedupe_key）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-IDE-001 | 幂等原因重复 award 只记一次 | 1. 对同一 `referenceType/Id` 连续调用两次 `award(..., 'daily_checkin', ...)` | 仅生成 1 条流水，`dedupe_key` 唯一索引拦截第二次 | 待执行 | 迁移 `2026_08_25_000010_add_dedupe_key...`、`award()` 的 try/catch | BU | 待分配 | 是 |
| PS-IDE-002 | 不同引用各记一次 | 1. 相同原因、不同 `referenceId` 调两次 | 生成 2 条流水 | 待执行 | 同上 | BU | 待分配 | 是 |
| PS-IDE-003 | 非幂等原因不约束 | 1. 对 `tip` 等无幂等键的原因多次 award | 全部成功（`dedupe_key` 为 NULL，唯一索引忽略 NULL） | 待执行 | `award()` 中 `isIdempotent` 判定 | BU | 待分配 | 是 |
| PS-IDE-004 | 并发重复不超发 | 1. 并发 10 次相同幂等 award | 最终仅 1 条，余额不重复增加 | 待执行 | 数据库唯一约束、事务 | BI | 待分配 | 是 |

## 三、原子转账 / 打赏（transfer）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-TRF-001 | 打赏原子转账 | 1. A 给 B 打赏 100 2. 检查双方余额 | A 减 100、B 加 100，各记一条 `tip.out`/`tip.in` 流水 | 待执行 | `PointsRepository::transfer()`、`TipPostController` | BI | 待分配 | 是 |
| PS-TRF-002 | 余额不足回滚 | 1. A 余额 50，打赏 100 | 整体失败，A 余额不变、不产生任何流水、`PostTip` 不入 | 待执行 | `transfer()` 内 `db->transaction()` | BI | 待分配 | 是 |
| PS-TRF-003 | 打赏落库 PostTip | 1. 成功打赏后查 `post_tips` | 存在对应 `post_id/user_id/amount` 记录 | 待执行 | `PostTip` 模型 | BI | 待分配 | 是 |
| PS-TRF-004 | transfer 内部一致性 | 1. 单元测试 mock `deduct` 抛异常 | `award` 不被调用，事务回滚 | 待执行 | `transfer()` 编排 | BU | 待分配 | 是 |

## 四、PointReason 注册表

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-REA-001 | 内置原因齐全 | 1. 调用 `PointReason::builtIn()` 或容器解析 | 返回 19 条，key 唯一 | 待执行 | `src/Support/PointReason.php` | BU | 待分配 | 是 |
| PS-REA-002 | 未知原因回退 | 1. `reasonLabel('unknown_x')` | 回退为原代码或默认文案，不报错 | 待执行 | `reasonLabel.ts` | FE | 待分配 | 是 |
| PS-REA-003 | 分类与上限标志正确 | 1. 遍历 builtIn | `category`、`countsTowardDailyCap` 字段与文档一致 | 待执行 | 同 PS-REA-001 | BU | 待分配 | 是 |

## 五、事件（PointsTransactionRecorded）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-EVT-001 | 创建即触发事件 | 1. 创建一条 `PointTransaction` | `PointsTransactionRecorded` 被分发一次 | 待执行 | 模型 `booted()` | BU | 待分配 | 是 |
| PS-EVT-002 | 监听器不改写仓库 | 1. 注册监听器计次 2. 触发 award | 监听器收到事件、仓库行为不受影响 | 待执行 | `PointSystemServiceProvider` 事件注册 | BI | 待分配 | 是 |

## 六、CSV 导出

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-CSV-001 | 管理员导出字段完整 | 1. 点击导出 2. 解析生成的 CSV | 含表头与全部流水列，编码正确（UTF-8 BOM） | 待执行 | `TransactionsPanel.exportCsv()` | FE | 待分配 | 否 |
| PS-CSV-002 | 普通用户仅导出自己 | 1. 用户 A 在 forum 页导出 | CSV 仅含 A 的流水，不含他人 | 待执行 | `UserTransactionsPage.exportCsv()` | FE | 待分配 | 否 |

## 七、目录缓存（ForumAttributes）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-CAT-001 | 缓存命中（60s） | 1. 两次请求同一目录 2. 第二次 mock 缓存命中 | 第二次不查库，直接返回缓存数组 | 待执行 | `rememberCatalog()`、`CATALOG_TTL` | BU | 待分配 | 是 |
| PS-CAT-002 | 签名变化失效 | 1. 改 settings（影响 `catalogSignature`）2. 重新请求 | 旧缓存失效，重算并返回新数据 | 待执行 | `catalogSignature()` | BU | 待分配 | 是 |
| PS-CAT-003 | 可用性按用户计算 | 1. 已拥有/未拥有装饰的用户分别请求 | `owned`/`purchasable` 等字段随用户正确 | 待执行 | `serializeAvailability()` | BI | 待分配 | 是 |

## 八、每日获取上限（daily cap）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-CAP-001 | 上限截断 | 1. 设 `daily_earn_cap=500` 2. 连续 award 累计超 500 | 超出部分被截断，当日合计不超过 500 | 待执行 | `award()` 上限逻辑、`settings` | BI | 待分配 | 是 |
| PS-CAP-002 | 幂等不重复计入 | 1. 同一幂等原因重复 award | 仅首次计入当日上限，重复不累加 | 待执行 | 与 PS-IDE-001 联动 | BI | 待分配 | 是 |

---

## 落地建议

- **后端（BU/BI）**：用 PHPUnit + SQLite 内存库，重点覆盖 `PointsRepository`（award/transfer/dedupe/cap）、`PointReason`、`ForumAttributes` 缓存。注意现有 `phpunit.xml` 仅含 `unit` suite 且 `failOnWarning/failOnRisky=true`，集成测试需另开 suite 或复用并提供 DB 启动逻辑。
- **前端（FE）**：Jest + RTL 覆盖 `reasonLabel`、`exportCsv`、`renderRow`；E2E 用 Cypress 走一遍 admin/forum 流水页与导出。
- **未覆盖项**：`PointEarnerRegistry`（目前未消费，建议补一条「未消费即不影响逻辑」的冒烟用例）与打赏频控（尚未实现，建议列为「待开发用例」）。
- **部署前置**：执行 `php flarum migrate`（含 `dedupe_key` 迁移）与 `php flarum cache:clear` 后再跑相关 BI/BI 用例。
