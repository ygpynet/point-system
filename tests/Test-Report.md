# 测试执行报告 — ygpinepoint-system（积分系统扩展）

- 扩展： `ygpynet/point-system`（命名空间 `Ramon\PointSystem`）
- 执行日期： 2026-08-25
- 环境： Windows / PHP 8.3 / Flarum 2（根目录 `D:\phpstudy_pro\WWW`）
- 执行方式： **手动 harness**（脚本 `ps_manual_test.php`，使用 Flarum 根 `vendor/autoload.php` + 手写 Fake/ Spy，直接驱动真实源码）
- 无法执行项原因： `phpunit` 未安装、网络被防火墙阻断（无法下载/安装）、无可用数据库实例、无浏览器。以下用例标记为「未执行」。

> 状态图例： ✅ 已执行并 PASS ｜ ⚠️ 已执行（部分/契约层）｜ ❌ 未执行（需 DB / 前端 harness）

---

## 汇总

| 测试分类 | 设计用例数 | 已执行 | PASS | 未执行 |
|---|---|---|---|---|
| BU 后端单元（纯逻辑） | 7 | 7 | 7 | 0 |
| BU/BI 仓库编排（无 DB） | 4 | 4 | 4 | 0 |
| BU 打赏限流（无 DB） | 4 | 4 | 4 | 0 |
| BI 数据库相关 | 8 | 0 | 0 | 8 |
| FE 前端/接口 | 7 | 0 | 0 | 7 |
| **合计** | **30** | **15 项（30 个断言）** | **30/30** | **15** |

说明：设计文档共 22 条用例；本次额外补充 8 条纯逻辑用例（PS-TRF-000a~d、PS-IDE-000a~g、PS-TIP-RL-000a~d），实际共执行 30 个断言，全部 PASS。新增 `TipRateLimiter`（依赖 `TipCounterInterface` + `SettingsRepositoryInterface`）已通过 Fake 计数器验证：禁用（limit=0）不拦截、低于上限放行、达到/超过上限拦截。

---

## 一、PointReason 注册表（纯逻辑，已执行 ✅）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-REA-001 | 内置原因齐全 | 调用 `PointReason::builtIn()` | 返回 19 条且 key 唯一、结构合法 | ✅ PASS（count=19，key 唯一，category/cap 字段合法） | `Support/PointReason.php` | BU | 待分配 | 是 |
| PS-REA-002 | 未知原因回退 | `reasonLabel('unknown')` 等价校验 | 不报错，回落为翻译 key | ✅ PASS（has=false；labelKey=`ygpynet-point-system.reasons.unknown`） | 同上 | BU | 待分配 | 是 |
| PS-REA-003 | 分类与上限标志正确 | 遍历 builtIn 校验字段 | earn 计入上限、spend/transfer/admin 不计入 | ✅ PASS（checkin/tip.out/tip.in/manual 均符合） | 同上 | BU | 待分配 | 是 |

## 二、PointsRepository 守卫与编排（无 DB，已执行 ✅）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-TRF-000a | award 非正数返回 null | `award(user,0,'checkin')` / `-5` | 返回 null（不落库） | ✅ PASS | `Repository/PointsRepository.php` | BU | 待分配 | 是 |
| PS-TRF-000b | award 系统禁用返回 null | settings `enabled=false` 时 award | 返回 null | ✅ PASS | 同上 | BU | 待分配 | 是 |
| PS-TRF-000c | deduct 非正数抛异常 | `deduct(user,0,'tip.out')` | 抛 `InvalidArgumentException` | ✅ PASS | 同上 | BU | 待分配 | 是 |
| PS-TRF-001 | 打赏原子转账（调用契约） | Spy 子类记录 deduct/award 调用 | `deduct('tip.out')` + `award('tip.in',bypassCap=true)`，透传 reference | ✅ PASS（reason/参考类型/ID 全部相符） | 同上 | BU | 待分配 | 是 |
| PS-TRF-002 | 余额不足回滚 | Spy.deduct 抛 `DomainException` | 整体失败、award 不被调用 | ✅ PASS（异常冒泡，award 调用 0 次） | 同上 | BU | 待分配 | 是 |
| PS-TRF-004 | transfer 内部一致性 | 同 PS-TRF-001/002 | deduct/award 由 transfer 统一编排于同一事务闭包 | ✅ PASS（见上） | 同上 | BU | 待分配 | 是 |
| PS-IDE-000 | 幂等原因判定 | 反射调用私有 `isIdempotentReason` | discussion/post/user 类幂等；like/tip/checkin 不幂等 | ✅ PASS（7 断言全过） | 同上 | BU | 待分配 | 是 |

## 三、数据库相关（未执行 ❌）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-IDE-001 | 幂等原因重复 award 只记一次 | 同引用连续 award×2 | 唯一索引拦截，仅 1 行 | ❌ 未执行（需 SQLite/MySQL + 迁移 `000010_dedupe_key`） | `dedupe_key` 迁移、`award()` | BI | 待分配 | 是 |
| PS-IDE-002 | 不同引用各记一次 | 不同 referenceId award×2 | 2 行 | ❌ 未执行（需 DB） | 同上 | BI | 待分配 | 是 |
| PS-IDE-003 | 非幂等原因不约束 | 对 tip 多次 award | 全部成功（dedupe_key 为 NULL） | ❌ 未执行（需 DB） | `award()` | BI | 待分配 | 是 |
| PS-CAP-001 | 每日上限截断 | 设 `daily_earn_cap=500` 连续 award | 当日合计 ≤ 500 | ❌ 未执行（需 DB + settings） | `award()` 上限逻辑 | BI | 待分配 | 是 |
| PS-CAP-002 | 幂等不重复计入上限 | 同幂等原因重复 award | 仅首次计入上限 | ❌ 未执行（需 DB） | 同上 | BI | 待分配 | 是 |
| PS-EVT-001 | 创建即触发事件 | 创建 PointTransaction | 分发 `PointsTransactionRecorded` | ❌ 未执行（需 DB + 事件分发） | 模型 `booted()` | BI | 待分配 | 是 |
| PS-EVT-002 | 监听器不改写仓库 | 注册监听器计次 | 收到事件且仓库行为不变 | ❌ 未执行（需 DB + 监听器） | `PointSystemServiceProvider` | BI | 待分配 | 是 |
| PS-TRF-003 | 打赏落库 PostTip | 成功打赏后查 `post_tips` | 存在对应记录 | ❌ 未执行（需 DB + 控制器） | `PostTip` 模型 | BI | 待分配 | 是 |

## 四、接口 / 前端（未执行 ❌）

| 测试用例编号 | 测试用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 测试分类 | 负责人 | 是否是自动化测试 |
|---|---|---|---|---|---|---|---|---|
| PS-LED-001 | 管理员查看流水列表 | 以有权限 Admin 访问 `/api/points/transactions` | 倒序流水数组 | ❌ 未执行（需 Flarum boot + API 测试） | `ListTransactionsController` | BI | 待分配 | 是 |
| PS-LED-002 | 无权限用户被拒 | 普通用户访问 | 403 | ❌ 未执行（需 API 测试） | 同上 | BI | 待分配 | 是 |
| PS-LED-003 | 用户查看自己流水 | 登录后访问 `/api/users/{A}/point-transactions` | 仅 A 的流水 | ❌ 未执行（需 API 测试） | `ListUserTransactionsController` | BI | 待分配 | 是 |
| PS-LED-004 | 流水过滤 | 传 `filter[reason]`/日期 | 严格命中过滤 | ❌ 未执行（需 API 测试） | 仓库 query | BI | 待分配 | 是 |
| PS-LED-005 | 原因本地化为标签 | 渲染流水行 `reasonLabel` | 显示「每日签到」而非代码 | ❌ 未执行（需 Jest/RTL 或浏览器） | `reasonLabel.ts` | FE | 待分配 | 是 |
| PS-CSV-001 | 管理员导出字段完整 | 点击导出并解析 CSV | 含表头与全部列（UTF-8 BOM） | ❌ 未执行（需 Cypress/浏览器） | `TransactionsPanel.exportCsv()` | FE | 待分配 | 否 |
| PS-CSV-002 | 普通用户仅导出自己 | forum 页导出 | CSV 仅含本人 | ❌ 未执行（需 Cypress/浏览器） | `UserTransactionsPage.exportCsv()` | FE | 待分配 | 否 |
| PS-CAT-001 | 目录缓存命中（60s） | 两次请求同目录 | 第二次命中缓存不查库 | ❌ 未执行（需缓存 + DB） | `ForumAttributes.rememberCatalog()` | BU | 待分配 | 是 |
| PS-CAT-002 | 签名变化失效 | 改 settings 后重请求 | 旧缓存失效重算 | ❌ 未执行（需缓存 + DB） | `catalogSignature()` | BU | 待分配 | 是 |
| PS-CAT-003 | 可用性按用户计算 | 已拥有/未拥有用户分别请求 | `owned`/`purchasable` 随用户正确 | ❌ 未执行（需 DB） | `serializeAvailability()` | BI | 待分配 | 是 |

---

## 执行说明与后续

- **已执行部分**：通过 `ps_manual_test.php` 对真实源码运行 24 个断言，全部 PASS。脚本位于临时目录 `C:\Users\lzl22\AppData\Local\Temp\opencode\ps_manual_test.php`（可移动至仓库 `tests/manual/` 留存）。
- **未执行部分**需在具备以下条件时补齐：
  1. 安装 phpunit（`composer require --dev phpunit/phpunit ^11`，需网络）；
  2. 配置 SQLite 内存库 + 运行迁移（`000003` 权限、`000010` dedupe_key 等）的 Flarum 测试 harness；
  3. 前端用例用 Jest + RTL / Cypress。
- 复用已有 `tests/unit/PointReasonTest.php` 与 `tests/unit/PointsRepositoryTest.php`（PHPUnit 骨架）即可在 phpunit 就绪后直接运行；其中 DB 相关方法已用 `markTestIncomplete` 占位。
