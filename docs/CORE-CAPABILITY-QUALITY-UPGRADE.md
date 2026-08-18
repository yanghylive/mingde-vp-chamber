# 核心能力质量升级开发指导

> 面向 work buddy 的施工手册。目标是把当前已经具备业务闭环的新增能力，提升到可以承受并发、重试、退款、跨租户运营和真实生产故障的质量级别。
>
> 文档版本：v1.0  
> 适用分支：`main` 及后续开发分支  
> 维护原则：代码、迁移、OpenAPI、测试、运行手册必须在同一个任务中完成，不接受“代码先合并、契约以后补”。

## 1. 先读这份文档的人要知道什么

当前仓库的基础会员、支付事实、活动报名和积分交易已经形成了比较好的实现模式：

- 交易动作有租户隔离和服务层边界。
- 活动支付投影使用 inbox、事件指纹、事务和锁处理重复消费。
- 商品积分兑换已经有服务端价格校验、积分余额更新和幂等键。
- 项目有 `check-project.sh`、前端构建检查和 OpenAPI 校验。

本轮质量升级应当把这些成熟模式复制到新增能力，而不是另起一套“能跑就行”的实现。

本手册覆盖：

1. 结算与打款：最高优先级，涉及真实资金。
2. 大咖预约：时段一致性、预约幂等、取消和历史数据。
3. AI 双身：调用幂等、积分计费、隐私、配额和检索性能。
4. 通知：广播投递、用户已读、重试、审计。
5. 跨切面治理：租户、权限、CORS、OpenAPI、源码锁、CI、监控。

不在本手册范围内：CRMEB 上游业务重构、前端视觉重做、已有 G1/G2 活动功能的无关重构。

## 2. 当前基线与硬约束

### 2.1 重要代码入口

| 能力 | 当前入口 | 施工时必须先读的文件 |
|---|---|---|
| 结算 | `SettlementService`、`SettlementAdminController` | `backend/custom/app/chamber/services/SettlementService.php`、`backend/custom/app/chamber/controller/SettlementAdminController.php` |
| 预约 | `ExpertScheduleController`、`SlotAdminController` | `backend/custom/app/chamber/controller/ExpertScheduleController.php`、`backend/custom/app/chamber/controller/SlotAdminController.php` |
| AI | `AiTwinService`、`KaypalGateway` | `backend/custom/app/chamber/services/AiTwinService.php`、`backend/custom/app/chamber/coaching/KaypalGateway.php` |
| 通知 | `NotificationController`、`NotificationAdminController` | `backend/custom/app/chamber/controller/NotificationController.php`、`backend/custom/app/chamber/controller/NotificationAdminController.php` |
| 路由/CORS | Chamber 路由和中间件 | `backend/custom/app/chamber/route/route.php`、`backend/custom/app/chamber/middleware/ChamberCorsMiddleware.php` |
| 质量门禁 | 项目检查、G2 检查、前端构建 | `scripts/check-project.sh`、`scripts/check-g2-activity-core.sh`、`scripts/check-frontend-build.sh` |
| 契约 | OpenAPI 和实现状态 | `backend/custom/openapi/chamber-openapi.yaml`、`backend/custom/openapi/validate.rb` |

### 2.2 当前已知基线问题

这些不是待讨论项，而是 work buddy 需要在任务拆分时直接纳入的验收条件：

- 结算任务按全局扫描执行，真实通道调用前没有可靠的任务占用状态；外部调用成功后进程崩溃可能导致重复打款。
- 结算管理接口可以直接传订单号和金额，缺少来源订单核验、审批、审计和幂等键；支付完成流程尚未自动生成结算单。
- 预约没有过期时段、重叠时段、模式/地点匹配和客户端幂等的完整约束；时段物理删除会损坏历史展示。
- AI 调用在扣积分之前发生，重复请求可能重复消耗上游成本；记忆返回和身份披露没有形成正式隐私策略。
- 广播通知只有一行数据，已读状态不能按用户隔离；通知删除为物理删除。
- ~~CORS 的显式 OPTIONS 路由没有覆盖全部新增路由。~~（已修复，2026-08-17：OPTIONS 白名单补齐 client/errors、client/events、coaching×5、knowledge/upload）
- ~~OpenAPI、旧 G2 门禁和 CRMEB 源码锁各自指向不同基线~~（已收敛，2026-08-17：OpenAPI 0.6.0/38/151 与 g1 门禁同步）
- 主机没有 PHP 运行时，因此本地只能完成脚本、Ruby、Node 和静态检查，PHP 集成测试必须在 Docker/CI 中完成。

### 2.2.1 微信支付（2026-08-18 新增，与 3010 ai-content 同一套逻辑）

- **实现**：ChamberWechatPayService（APIv3 直连，商户号 1116143786），JSAPI（wx.requestPayment 二次签名）+ NATIVE 下单；回调平台证书 RSA 验签（无证书拒绝处理）+ AES-256-GCM 解密 + 金额一致性校验（服务端按业务单应付生成支付单）+ 幂等入账；`config-status` 脱敏检查。
- **接入**：会籍购买（membership，按 order_no 反查 CRMEB 订单并校验归属 + pay_price 应付）；兑换现金补差（exchange，校验归属 + cash_cost）。金额与订单归属一律服务端计算，不信任客户端。
- **入账**：membership → MembershipPaymentCompletionService::complete（事件链自动升级会员）；exchange → 兑换订单 pending→paid。
- **前置（商户平台）**：真实 APIv3 密钥/证书序列号/私钥、商户号关联明德小程序 appid wx94d063705b9e7c7f、微信平台证书（WXPAY_PLATFORM_CERT_PATH）。配置未齐时下单返回 need_config，不假报成功。

### 2.3 不可违反的工程原则

1. **所有写操作都必须有租户条件。** 不能只依赖 URL 或当前管理员上下文推断租户。
2. **所有可重试写操作都必须有幂等键。** 幂等记录必须和业务结果在同一事务中落库。
3. **外部调用不放在数据库事务里长时间持锁。** 使用“持久化任务 + claim/lease + 外部调用 + 回写结果”。
4. **金额使用整数分或 `Money`。** 不在业务判断中使用 PHP `float`。
5. **状态变化必须经过显式状态机。** 不允许在多个控制器里散落字符串赋值。
6. **敏感数据默认不返回。** AI 记忆、支付原始响应、内部错误只能按权限和用途暴露。
7. **先改契约再改调用方，或在同一 PR 中同步完成。** 新接口必须有 OpenAPI 和测试。
8. **CRMEB 子模块保持只读。** 自研改动放在 `backend/custom/`、`scripts/` 或独立迁移中。

## 3. 交付顺序

不要并行把所有能力一起改。推荐按以下顺序交付：

| 阶段 | 工作包 | 生产阻断级别 | 依赖 |
|---|---|---:|---|
| 0 | 基线、源码锁、契约和测试入口统一 | P0 | 无 |
| 1 | 结算安全重构 + 支付完成自动接入 | P0 | 阶段 0 |
| 2 | 预约状态机与幂等 | P1 | 阶段 0 |
| 3 | AI 计费、隐私和异步化 | P1 | 阶段 0；部分依赖队列 |
| 4 | 通知投递与用户已读 | P1 | 阶段 0；可与阶段 2/3 并行 |
| 5 | 权限、监控、读模型和性能 | P1/P2 | 阶段 1-4 的结果 |

每个阶段必须有独立 PR、迁移文件、测试和回滚说明。一个阶段未达到退出标准，不要继续打开真实通道或扩大灰度范围。

## 4. 阶段 0：统一基线、契约和门禁

### 4.1 目标

让所有人知道“当前到底锁定了哪个 CRMEB 源码、哪些接口已实现、哪些模块被 CI 覆盖”。这一步不改变业务行为，但不完成它，后续测试结果没有可信度。

### 4.2 具体任务

#### A. 建立单一源码锁

当前 `scripts/check-project.sh` 期望定制提交，而 `scripts/prepare-local-frontend.sh` 和 `backend/custom/commerce/audit_crmeb_v6.rb` 仍要求 `v6.0.0` 原始 tag。请建立一个唯一来源，例如：

```text
PROJECT_MANIFEST.json
  crmeb_source:
    upstream_tag: v6.0.0
    upstream_commit: 7dcddffff73ec542d689f159724296351f29ea9a
    project_commit: 0791fbf8b0d75bb8af0faa25c6305535368559f3
```

脚本语义要分开：

- `upstream_commit`：用于 CRMEB 兼容性审计。
- `project_commit`：用于 Chamber 当前工作区和前端构建。
- 如果必须在上游子模块上保留定制提交，必须明确这是“补丁层”，不能继续假装 HEAD 是 upstream tag。

退出标准：

- `check-project.sh`、`prepare-local-frontend.sh`、commerce audit 对同一份 manifest 读取锁定信息。
- `ruby backend/custom/commerce/audit_crmeb_v6.rb` 不再因 tag/commit 语义冲突失败。
- 运行手册明确部署使用的是哪个 commit，而不是只写“CRMEB v6”。

#### B. 扩大质量门禁覆盖范围

把以下目录纳入 PHP lint、迁移 inventory、路由存在性和最小数据库测试：

```text
backend/custom/app/chamber/services/SettlementService.php
backend/custom/app/chamber/services/AiTwinService.php
backend/custom/app/chamber/controller/ExpertScheduleController.php
backend/custom/app/chamber/controller/SlotAdminController.php
backend/custom/app/chamber/controller/NotificationController.php
backend/custom/app/chamber/controller/NotificationAdminController.php
backend/custom/app/chamber/controller/MonitorController.php
backend/custom/app/chamber/jobs/SettlementSettleJob.php
backend/custom/app/chamber/database/migrations/*settlement*
backend/custom/app/chamber/tests/*settlement*
backend/custom/app/chamber/tests/*appointment*
backend/custom/app/chamber/tests/*ai*
backend/custom/app/chamber/tests/*notification*
```

不要只把文件名加入数组；每个新能力至少要有一个失败路径测试和一个并发/重试测试。

#### C. 修正 OpenAPI 和 OPTIONS 路由

- 已经实现的接口标为 `implemented`，仍未实现的接口保留 `planned`。
- 对所有 `GET/POST/PUT/PATCH/DELETE` 路由生成或补齐 OPTIONS 路由。
- 对 `v1/coaching/*`、`v1/client/errors`、`v1/client/events` 和 `v1/me/*` 的 mutation 路由做浏览器预检测试。
- OpenAPI 中统一描述 `Idempotency-Key`、`X-Request-Id`、错误码和租户头。

### 4.3 阶段 0 验收命令

```bash
./scripts/check-project.sh
./scripts/check-frontend-build.sh
ruby backend/custom/openapi/validate.rb
ruby backend/custom/commerce/audit_crmeb_v6.rb
```

在没有 PHP 的主机上，不要伪造通过结果；应在 Docker/CI 中运行：

```bash
./scripts/check-g2-activity-core.sh ci
```

## 5. 阶段 1：结算与真实打款安全重构

这是最高优先级。没有完成本阶段，`settlement.live` 必须保持关闭。

### 5.1 目标

把结算改造成可信的异步工作流：支付事实确认后生成一张不可变的结算快照，明细可重试但不会重复创建外部打款；所有人工动作有权限、审批和审计；失败可以进入待处理状态而不是永久卡住。

### 5.2 建议状态机

#### 结算单 `ch_settlement.status`

```text
pending -> processing -> done
pending -> processing -> partial
pending -> processing -> failed
partial -> processing -> done
failed  -> processing -> partial/done/failed
```

`done` 是终态。`failed` 不是无限自动重试，超过阈值进入人工处理队列。

#### 明细 `ch_settlement_detail.status`

```text
pending -> claimed -> paying -> success
pending -> claimed -> paying -> uncertain
paying  -> success/failed/uncertain
failed  -> claimed
uncertain -> reconciling -> success/failed
success -> reversed
```

`uncertain` 表示外部结果未知，不能直接再次打款；必须先调用渠道查询接口或人工对账。

### 5.3 数据库改造

新增迁移，建议至少包含：

```sql
ALTER TABLE ch_settlement_detail
  ADD COLUMN claim_token VARCHAR(64) NOT NULL DEFAULT '',
  ADD COLUMN claim_expire_time INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN last_attempt_time INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN next_retry_time INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN status_reason VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE ch_payout_record
  ADD COLUMN request_payload_hash CHAR(64) NOT NULL DEFAULT '',
  ADD COLUMN query_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN last_query_time INT UNSIGNED NOT NULL DEFAULT 0;
```

同时补充索引：

```sql
KEY idx_due (tenant_id, status, next_retry_time, id),
KEY idx_claim (status, claim_expire_time),
UNIQUE KEY uk_detail_channel (settlement_detail_id, channel)
```

规则表还需要增加版本或生效时间。生成结算单时，必须把规则比例、接收方、渠道和金额写入明细快照，后续改规则不能影响历史结算。

### 5.4 服务层改造步骤

#### A. 结算生成必须来自可信支付事实

不要让管理员传 `order_amount` 就能生成结算。新增一个内部服务入口，例如：

```php
SettlementService::settleFromCommerceEvent(CommerceEvent $event): SettlementResult
```

入口必须验证：

- 事件类型是已支付或支付完成。
- 订单租户、订单号、业务类型和已支付金额来自服务端订单上下文。
- 订单状态是支付完成，金额与事件金额一致。
- 退款或取消不能走“支付完成”路径。
- 事件指纹和结算唯一键可安全重放。

接入位置：`MembershipPaymentCompletionService` 及事件报名支付投影的成功分支。结算失败不能回滚已经确认的支付事实，应写入 inbox/outbox，异步重试并报警。

#### B. 生成结算单要处理唯一键竞争

当前“先查后插”无法避免并发竞争。改为：

1. 在事务中读取并锁定规则版本。
2. 使用 `(tenant_id, business_type, order_no)` 唯一键插入。
3. 捕获 duplicate key 后重新读取已有结算单，返回同一个结果。
4. 不允许把唯一键异常当作普通失败重试。

#### C. 执行明细使用 claim/lease

推荐流程：

```text
SELECT pending/failed/claim-expired rows
  -> UPDATE ... WHERE status IN (...) AND claim_token=''
  -> 提交事务
  -> 调用渠道
  -> 写 payout_record / detail result
  -> close settlement
```

关键点：

- claim 更新必须带原状态条件，受影响行数不是 1 就跳过。
- lease 到期只能被重新认领；`paying` 状态不允许无条件重打。
- 外部调用前先写 `ch_payout_record(status=pending)`，并使用稳定的 `idempotency_key`。
- 外部调用成功后写 `success`；超时或网络错误写 `uncertain`，进入查询队列。
- 渠道返回的订单号、金额、接收方必须和本地请求做一致性校验。

不要依赖 `ch_payout_record` 的唯一键来防止重复外部转账。唯一键只能防止重复写本地记录，不能撤销已经发出的渠道请求。

#### D. 退款抵扣必须原子化

`recordRefundDebit()` 和 `applyDebit()` 不允许使用“查余额、PHP 加减、再更新”的模式。改为：

- 在事务内 `SELECT ... FOR UPDATE` 锁定余额行。
- 缺行时用唯一键插入，并处理并发 duplicate key 后重读。
- 金额统一用 `Money::toMinor()`，数据库展示仍可保存 DECIMAL 字符串。
- 增加余额变更流水表，记录 refund、settlement_debit、manual_adjustment 三类来源。

### 5.5 管理接口安全要求

`SettlementAdminController` 的规则保存、手动重试、人工确认和人工调整都必须：

- 校验 `AuthenticatedAdminContext` 权限，例如 `settlement.read`、`settlement.rule.write`、`settlement.retry`、`settlement.manual_adjust`。
- 写入审计日志：管理员、租户、目标对象、旧值、新值、原因、request id。
- 手动结算只能选择已存在且已支付的订单，金额从订单读取，不接受客户端金额作为权威值。
- `run-due` 必须显式接收 tenant 或由系统 job 按租户分批执行，不能全局扫库。
- 手动重试必须带 `reason`，并限制最大次数。

### 5.6 结算测试矩阵

最低必须覆盖：

| 场景 | 断言 |
|---|---|
| 同一订单并发生成 20 次 | 只有一张结算单和一组明细 |
| 同一明细并发 worker | 只有一个 claim 成功 |
| 渠道成功后本地进程崩溃 | 重试先查渠道/幂等，不产生第二次打款 |
| 渠道超时 | 明细进入 `uncertain`，不直接再次付款 |
| 规则变更后重试旧结算 | 使用历史快照，不使用新规则 |
| 退款抵扣并发写入 | 余额和流水不丢失、不覆盖 |
| 跨租户订单号相同 | 互不影响 |
| 伪造金额/未支付订单 | 返回 409/422，不生成结算 |
| 达到重试上限 | 进入人工队列，父单可显示 `partial/failed` |

### 5.7 退出标准

- 真实通道默认关闭，mock 通道测试通过。
- 所有支付完成业务都能产生结算事件，且可重放。
- 并发、崩溃注入、渠道查询和人工处理测试通过。
- 结算列表能展示订单来源、规则版本、渠道状态、最近错误和下一次重试时间。
- 没有任何“直接传金额即可打款”的管理员入口。

## 6. 阶段 2：预约状态机与历史一致性

### 6.1 目标

确保一个时段最多被一个有效预约占用；过期和取消行为可解释；历史预约即使时段被关闭也能完整展示。

### 6.2 数据模型

建议新增迁移：

```sql
ALTER TABLE ch_expert_slot
  ADD COLUMN deleted_at INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE ch_appointment
  ADD COLUMN slot_start_time INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN slot_end_time INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN location TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN booking_key VARCHAR(64) NOT NULL DEFAULT '',
  ADD COLUMN cancel_reason VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE ch_appointment
  ADD UNIQUE KEY uk_booking_key (tenant_id, member_id, booking_key);
```

预约创建时写入时段和专家快照，不依赖后续 join 才能渲染历史。

### 6.3 预约创建流程

1. 校验 `slot_id`、`mode` 和幂等键格式。
2. 在事务中锁时段。
3. 校验 `status=open`、`deleted_at=0`、`start_time > now`、`end_time > start_time`。
4. 校验专家存在、属于同租户且可预约。
5. 校验 `mode` 与 `location` 一致。
6. 读取服务端定价，不接受客户端金额。
7. 用已有积分账户版本/行锁模式扣积分；若现金预约，创建待支付订单而不是把现金写成 `0.00`。
8. 写预约、积分流水、幂等结果，并把时段更新为 `full/booked`。
9. 事务提交后发送预约确认通知和结算事件。

幂等重放必须返回第一次创建的预约对象，不得因为客户端超时而返回“时段已被占用”。

### 6.4 取消流程

- 明确取消截止时间，例如开始前 24 小时可自助取消，之后只能管理员处理。
- 锁预约和时段，校验状态转换只允许 `confirmed -> cancelled`。
- 积分退款和积分流水反向记录必须与状态变更同事务。
- 重新开放时段只能在没有其他有效预约的条件下进行。
- 取消操作写审计和通知；重复取消返回原结果。

### 6.5 档期管理

`SlotAdminController::store()` 必须新增：

- 专家存在且为当前租户可管理专家。
- 开始时间必须在当前时间之后。
- 同一专家的时间区间不可重叠。
- 线下时段需要地址/场地字段，线上时段需要会议方式字段。

删除改成软删除：

- 未被预约的时段可直接关闭或软删除。
- 已被预约的时段不能物理删除，只能关闭并保留历史。
- 已完成或已取消的预约永远使用快照字段展示。

### 6.6 预约测试矩阵

- 过去时段、零时长时段、结束早于开始：拒绝。
- 同一专家重叠时段：拒绝。
- 线上预约选线下时段：拒绝。
- 两个会员同时抢同一时段：一个成功，一个明确返回冲突。
- 同一会员重复使用同一 `Idempotency-Key`：返回同一预约。
- 预约创建请求响应丢失后重试：不重复扣积分。
- 取消重复提交：只退款一次。
- 删除已预约时段：历史预约仍显示完整时间和专家信息。

## 7. 阶段 3：AI 双身的计费、隐私和可用性

### 7.1 目标

让 AI 调用在成本、用户体验和隐私上可解释：一次用户请求对应一次可追踪的业务结果；失败可重试，成功不重复收费；训练和检索不阻塞主请求。

### 7.2 推荐的请求状态机

新增 `ch_ai_request` 或在现有 chat 表中增加独立请求字段：

```text
created -> points_reserved -> upstream_running -> succeeded
created -> points_reserved -> upstream_running -> failed -> refunded
created -> points_reserved -> upstream_running -> uncertain -> reconciling
```

至少包含：

```text
tenant_id, expert_id, requester_member_id, idempotency_key,
request_hash, provider, provider_request_id, status,
points_reserved, points_charged, points_refunded,
response_json, error_code, started_at, finished_at, latency_ms
```

唯一键：`(tenant_id, requester_member_id, idempotency_key)`。

### 7.3 对话流程

1. 校验分身状态、会员权限、输入长度和敏感内容策略。
2. 用幂等键创建请求记录；如果已存在，按状态返回已有结果。
3. 在事务中锁积分账户并预占积分，写 reservation ledger。
4. 提交事务后调用 AI provider。
5. 成功：写完整响应、provider request id、真实模型、token/成本、耗时；把预占转正式扣费。
6. 失败：按失败类型退款；明确区分 provider 拒绝、超时、内部错误和未知结果。
7. 所有状态变化写 usage/audit，不在请求结束时硬编码 `latency=0` 或固定模型名。

不要用“先调用模型、成功后再扣积分”的方案，也不要把上游调用包在长事务中。

### 7.4 身份与隐私

- 面向会员明确标识这是 AI 生成的回答，不能通过系统提示故意隐瞒。
- 专家必须明确同意哪些训练材料可用于分身，哪些只允许私人使用。
- 记忆增加 `visibility`、`consent_version`、`redacted_content` 或等效字段。
- 默认不在公开 profile 接口返回完整 memory；只返回数量、更新时间和经授权的摘要。
- 训练对话和会员对话按访问主体隔离；管理员查看原文需要审计。
- 删除/撤回训练材料后，异步使相关 memory、knowledge embedding 失效并重建索引。

### 7.5 训练和检索异步化

当前训练和知识库向量化在请求内同步调用。改为：

- `train` 只创建训练任务和素材快照，返回 `job_id`。
- 队列 worker 负责抽取、向量化、memory upsert 和进度更新。
- 每个任务有 attempt、next_retry_time、last_error、lease。
- 对话检索使用预计算的 BM25/向量索引或缓存；不能每次加载 200 行并在 PHP 中全量计算。
- embedding provider 调用设置超时、熔断、重试上限和成本上限。

### 7.6 AI 测试矩阵

- 同一幂等键并发请求：只调用一次 provider，只扣一次积分。
- provider 成功但本地写库失败：重试可通过 provider request id/幂等键恢复。
- provider 超时：进入 `uncertain` 或退款，不直接再次扣费。
- 积分不足：不产生 provider 调用。
- 非专家会员请求创建分身：拒绝。
- 未授权读取 profile：不返回 memory 原文。
- 删除知识条目：后续检索不可命中已删除内容。
- 超长输入、敏感输入、provider 5xx：错误码稳定且不泄露内部响应。

## 8. 阶段 4：通知投递模型

### 8.1 目标

将“通知内容”和“每个用户是否收到/已读”分开，支持广播、定向通知、重试和审计。

### 8.2 数据模型

建议新增三张表：

```text
ch_notification                 通知定义：标题、正文、类型、创建者、计划时间、状态
ch_notification_recipient       收件人投递：notification_id、member_id、delivery_status、attempt_count
ch_notification_read            已读状态：notification_id、member_id、read_time
```

推荐唯一键：

```text
UNIQUE(notification_id, member_id)
UNIQUE(notification_id, member_id) on read table
```

广播消息不要为每个用户同步插入数百万行；可以保存广播定义，再按用户首次读取时 materialize 收件人状态，或采用“广播版本 + 用户游标”模型。第一版可按租户规模选择实现，但必须保证已读按用户隔离。

### 8.3 接口要求

用户端至少提供：

```text
GET  /v1/me/notifications
POST /v1/me/notifications/:notification_id/read
POST /v1/me/notifications/read-all
```

管理员端至少提供：

```text
POST   /admin/v1/notifications
PATCH  /admin/v1/notifications/:id
DELETE /admin/v1/notifications/:id
GET    /admin/v1/notifications/:id/delivery
```

删除应为软删除或撤回状态；已经投递的通知不能从审计上消失。

### 8.4 投递和重试

- 创建通知只写定义和 outbox 事件。
- worker 执行站内信、订阅消息、短信等通道。
- 每个通道独立记录 attempt、错误码、下一次重试时间。
- 同一收件人同一通知不得重复投递成功。
- 失败超过阈值进入 dead letter，管理端可查看和重放。
- 广播、定向和定时发送都必须带租户条件。

### 8.5 通知测试矩阵

- 两个用户读取同一广播：各自独立已读。
- 用户重复点击已读：幂等且不重复写流水。
- 广播创建后撤回：列表不再展示，但审计仍存在。
- worker 重启/重复执行：不重复产生成功投递。
- 发送通道失败：按退避重试，最终进入死信。
- 跨租户使用相同 notification id：不能读取或修改。

## 9. 阶段 5：权限、监控和性能

### 9.1 管理权限和审计

当前很多新增管理控制器没有显示调用细粒度权限断言。至少为以下动作建立权限：

```text
settlement.read
settlement.rule.write
settlement.retry
settlement.manual_adjust
appointment.manage
notification.read
notification.write
notification.delete
ai.manage
ai.memory.read
monitor.read
```

每个写操作审计：`admin_id、tenant_id、action、resource_type、resource_id、before、after、reason、request_id、created_at`。

不能把“只有超级管理员”当作长期权限模型；新增运营、财务和客服角色后，必须能按动作授权。

### 9.2 监控与告警

健康接口只返回必要信息，并至少做到：

- 生产环境需要认证或内网访问控制。
- 数据库、队列、支付渠道和 AI provider 分开探测。
- 告警按 `(tenant, component, error_code)` 去重并有冷却时间。
- webhook URL 使用 allowlist，禁止任意内网地址 SSRF。
- 记录指标：结算 pending/uncertain 数、AI provider latency/error/cost、预约冲突率、通知死信数。
- 日志写持久化日志系统或容器 stdout，由部署层采集；不要把 `/tmp` 当唯一日志存储。

### 9.3 查询性能

优先修复这些明显的 N+1/全量扫描：

- `ExpertScheduleController::myAppointments()` 对每条预约查询 slot 和 expert。
- 结算后台列表逐条查询明细。
- AI 检索每次加载大量知识并在 PHP 中计算。
- 结算 worker 无租户、状态、时间索引地扫描任务。

要求：列表接口使用 join/read model 或批量查询；大列表使用稳定排序和游标分页；所有 worker 查询必须有可用索引和 limit。

## 10. 统一接口和错误约定

### 10.1 幂等键

所有创建/扣费/取消/发送/重试接口接受：

```http
Idempotency-Key: <client-generated-key>
X-Request-Id: <trace-id>
```

服务端保存：请求主体 hash、响应状态码、响应 JSON、创建时间和过期时间。相同 key 不同 body 必须返回 `409 idempotency_key_reused`。

### 10.2 错误码

错误码要稳定、可机器判断，示例：

```text
request_validation_failed
tenant_forbidden
permission_denied
idempotency_key_reused
resource_state_conflict
insufficient_points
payment_fact_untrusted
settlement_uncertain
provider_timeout
manual_action_required
```

不要把 PHP 异常消息或第三方原始响应直接返回给小程序。

### 10.3 金额

- API 金额统一两位小数字符串，例如 `"199.00"`。
- 内部计算使用 `Money::toMinor()` 得到整数分。
- 比较金额必须比较 minor，不使用 `abs(float - float)`。
- 结算、退款抵扣、现金预约和商品兑换统一遵守同一规则。

## 11. Work buddy 的实施模板

每个 work buddy 领取一个工作包时，必须先提交以下内容，再开始写代码：

```md
## 工作包
- 名称：
- 负责人：
- 依赖：
- 是否涉及真实资金：是/否

## 当前行为
- 入口文件：
- 当前状态：
- 复现命令或测试：

## 设计
- 状态机：
- 数据迁移：
- 幂等键：
- 租户边界：
- 权限和审计：
- 外部调用/队列：

## 实施清单
- [ ] PHP 服务层
- [ ] 控制器和路由
- [ ] migration up/verify/down
- [ ] OpenAPI
- [ ] 正常路径测试
- [ ] 重试/并发/失败测试
- [ ] 日志和指标
- [ ] 回滚说明

## 验收证据
- 命令：
- 输出：
- 未覆盖风险：
```

提交拆分建议：

1. `docs/` 和 OpenAPI 设计。
2. migration + domain service。
3. controller/route + 权限。
4. 测试和质量门禁。
5. 运行手册、监控和灰度开关。

禁止把上述内容压成一个没有测试的“大 PR”。

## 12. 发布前总验收

### 12.1 自动检查

```bash
./scripts/check-project.sh
./scripts/check-frontend-build.sh
ruby backend/custom/openapi/validate.rb
ruby backend/custom/commerce/audit_crmeb_v6.rb
./scripts/check-g2-activity-core.sh ci
```

PHP/Docker 环境不可用时，必须在 CI 或本地 Docker 中补跑，不能只提交静态检查结果。

### 12.2 必须人工验证

- 同一订单、预约、AI 请求、通知在重复点击和网络超时下不会重复扣费、扣积分或投递。
- 两个租户使用相同订单号、slot id、notification id 时完全隔离。
- 数据库事务中途失败后，重试可以恢复，不留下“看起来成功但无法继续”的半状态。
- 真实结算通道关闭时，所有相关页面清楚显示 mock/待处理状态。
- 管理员只能看到自己租户和权限范围内的数据。
- 删除或撤回后，用户端行为正确，审计记录仍可追溯。

### 12.3 灰度顺序

```text
只读列表
  -> mock 写入
  -> 单租户内部测试
  -> 单渠道小额真实结算
  -> 多租户灰度
  -> 全量
```

真实资金能力必须有独立的 kill switch，且 kill switch 关闭时不能阻塞普通支付完成和会员权益发放。

## 13. 不要做的事情

- 不要为了通过唯一键测试，把 duplicate key 吞掉后返回“成功”而不重新读取结果。
- 不要在外部支付/AI/通知调用期间持有数据库事务锁。
- 不要把客户端传来的金额、专家价格、接收方名称作为权威数据。
- 不要用物理删除替代状态流转和审计。
- 不要以“当前只有超级管理员”为理由跳过权限模型。
- 不要把 OpenAPI、前端 API 封装和后端路由分开延期维护。
- 不要在没有并发、重试和故障测试的情况下打开真实通道。

## 14. 推荐首批 PR

为了让 work buddy 可以立即开工，首批建议按下面的 5 个 PR 拆分：

1. **PR-01 基线统一**：manifest 源码锁、OpenAPI implementation status、OPTIONS 路由、质量门禁覆盖。
2. **PR-02 结算安全核心**：settlement claim/lease、uncertain、规则快照、余额原子化、自动接入支付事件。
3. **PR-03 预约一致性**：slot 状态机、重叠校验、预约/取消幂等、快照字段和软删除。
4. **PR-04 AI 可靠性**：AI request 幂等、积分预占、provider usage、身份披露、记忆权限。
5. **PR-05 通知与运营**：收件人/已读模型、投递 worker、死信、权限、审计、告警去重。

PR-02 完成并通过故障注入测试之前，`settlement.live` 不得在生产配置中打开。PR-03/04/05 可以并行，但各自必须保留独立迁移和回滚路径。
