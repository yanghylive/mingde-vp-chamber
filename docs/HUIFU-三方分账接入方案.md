# 汇付天下支付与 4:4:2 三方分账接入方案

> 目标：将 Chamber 的收款与三方分账统一到汇付天下，支付成功后按 40% / 40% / 20% 实时分账。
>
> 本文是 work buddy 的开发输入，不是汇付接口字段的最终合同。汇付产品、商户资质、字段和签名方式以当前商户控台及汇付技术支持提供的生产/测试文档为准。

## 1. 结论

方向可行，但不能在现有 `WechatPay -> T+1 payout` 代码上直接把通道名改成汇付。

推荐采用汇付 SPIN 的“支付 + 商户分账”能力作为唯一资金链路：

```text
小程序/前端
    -> Chamber 创建本地支付单
    -> 汇付微信小程序支付
    -> 汇付异步通知/主动查单确认支付成功
    -> 汇付实时商户分账（A 40% / B 40% / C 20%）
    -> 汇付异步通知/主动查询确认分账最终态
    -> Chamber 入账、对账、展示
```

不要采用“微信收款后再从 Chamber 分别转给三家公司”的混合方案。那会产生两个资金系统，无法保证收款与分账的一致性，也无法把汇付的分账幂等、查询、退款能力纳入一个状态机。

汇付公开产品资料明确提供多级账户、实时分账、API 接入和可配置结算能力；SPIN 文档也提供商户分账配置、支付查询、退款、交易确认和对账查询等 API 类别：[汇付产品能力](https://www.huifu.com/products-services)、[SPIN 开发者文档](https://spin.cloudpnr.com/topds/index.html)、[SPIN 资金与支付 API](https://spin.cloudpnr.com/topds/acctPaymentAPI.html)。

## 2. 4:4:2 的业务定义必须先锁定

“三方 4:4:2”存在一个容易被忽略的歧义：

- 方案 A：A、B、C 三个汇付接收方分别得到 40%、40%、20%，三方合计 100%。
- 方案 B：A、B 两家公司得到 40%、40%，平台留存 20%；此时实际只有两个外部接收方，平台是第三个资金归属方。

开发前必须确定三方对应的汇付 `huifu_id`，以及平台是否作为收款商户/分账接收方参与分账。不能把“平台留存”默认为任意一个公司，也不能用 `receiver_name` 代替汇付账户。

汇付官方的分账配置查询会返回规则来源（接口配置或控台配置）、适用比例和接收方 `huifu_id` 列表：[官方商户分账配置查询](https://spin.cloudpnr.com/topds/queryMerSplitConfig.html)。实际能否配置 40% 的单方比例、最低留存、手续费归属、是否需要提额/审核，必须以当前商户合同和控台结果为准。

## 3. 接入前置资料（没有这些资料不进生产）

由产品/财务/汇付客户经理一次性补齐：

1. 汇付产品名称：确认是 SPIN 商户分账，还是其他聚合支付/延迟分账产品；确认实时分账还是支付后延迟分账。
2. 环境资料：测试和生产 API 地址、`sys_id`、`product_id`、平台 `huifu_id`、商户状态、回调地址白名单。
3. 密钥资料：商户 RSA 私钥、公钥/证书、证书序列号、签名算法（公开文档使用 RSA2），以及密钥轮换流程。私钥只进环境密钥管理，不进仓库和数据库明文。
4. 支付方式：小程序微信支付的具体产品和字段，是否需要用户 `openid`，汇付返回给前端的调起支付参数格式。
5. 三个接收方：三个公司的汇付 `huifu_id`、主体类型、实名/KYC 状态、是否允许实时分账、可接收币种和账户状态。
6. 分账规则：最大分账比例、最低平台留存、分账手续费、尾差处理、是否允许全额分账、分账失败后的资金冻结/退回行为。
7. 退款规则：分账前退款、分账后退款、部分退款、接收方余额不足时的处理和时限。
8. 对账资料：交易、分账、退款、结算文件的下载方式和保留周期。

控台欢迎页本身需要登录，当前无法从公开页面读取你们账号的产品开通状态；因此上面第 1、4、6、7 项不能靠猜接口完成。

### 3.1 已从合作商控台核对到的真实信息

当前登录的合作商控台显示：

- 渠道商号 / `sys_id`：`6666000164096817`；
- 当前开发产品号 / `product_id`：`EDUARK`，控台产品名称为“汇学账管家”；
- 合作商账户的微信支付、支付宝支付和“分账”能力均为“开通”；
- 合作商允许下级商户开通分账，分账手续费底价为 `0.01% + 0.01 元`；
- 当前控台商户列表只有 6 个已入驻商户，实际用于 Chamber 收款和三方分账的商户尚未添加；现有商户不能直接视为本项目的收款主体；
- “新增商户入驻”提供“闪电入驻”流程，表单的“账户与资金产品”区域包含“多方分账”勾选项；应以实际主体资料新建商户，并在进件时选择该产品；
- 控台 Webhook 当前没有配置端点。

因此当前阻塞点已经明确：不是合作商资格，而是实际收款商户的进件、该商户的“多方分账”产品开通，以及三个实际分账接收方的商户主体/KYC 配置。不要在代码里把 `sys_id` 当成收款方 `huifu_id`；支付和分账请求仍需要明确实际收款商户号及接收方 `huifu_id`。

## 4. 代码改造边界

### 4.1 保留的现有能力

`SettlementService` 已有金额按分计算、规则快照、claim/lease、幂等 payout 记录和失败退避，可作为内部结算编排的基础。现有实现位于 [SettlementService.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/services/SettlementService.php)。

### 4.2 必须替换的边界

当前 [SettlementChannelInterface.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/contracts/SettlementChannelInterface.php) 只有 `pay()`，而汇付需要支付下单、支付查单、分账下单、分账查询、退款、退款查询和回调验签。因此新增 `HuifuClient` 和 `HuifuPaymentChannel`，不要把 HTTP 代码塞进 `SettlementService`。

建议把接口升级为：

```php
interface PaymentProviderInterface
{
    public function createPayment(PaymentRequest $request): ProviderPaymentResult;
    public function queryPayment(string $merchantOrderNo): ProviderPaymentResult;
    public function createSplit(SplitRequest $request): ProviderSplitResult;
    public function querySplit(string $merchantSplitNo): ProviderSplitResult;
    public function refund(RefundRequest $request): ProviderRefundResult;
    public function queryRefund(string $merchantRefundNo): ProviderRefundResult;
}
```

实际字段以汇付合同为准；以上是内部接口，不是对外 OpenAPI。

### 4.3 新增数据模型

建议新增以下表，或在现有支付表上扩展等价字段：

#### `ch_payment_order`

统一记录支付单：`tenant_id`、业务类型/业务主键、金额（分）、`provider=huifu`、商户订单号、汇付订单号、支付状态、交易状态、请求序列号、回调时间、请求/响应摘要。

状态至少包含：

```text
created -> pending -> paid
                  -> closed
                  -> unknown
```

#### `ch_settlement_receiver`

保存经过审核的接收方映射：`tenant_id`、角色（A/B/C/platform）、`huifu_id`、主体名称、KYC 状态、启用时间、停用时间、审计人。

#### `ch_split_order` 与 `ch_split_order_detail`

保存一次分账请求及其快照：商户分账单号、汇付分账单号、支付单号、规则版本、总金额、手续费、平台留存、每个接收方的 `huifu_id`、比例、计划金额、实际金额、状态、查询次数、下次重试时间、最后错误。

历史订单必须保存分账快照，不能在订单完成后重新读取可变的控台规则。

## 5. 金额与规则

所有计算使用整数分，禁止浮点数。以订单金额 `10000` 分为例：

```text
A = floor(10000 * 40 / 100) = 4000
B = floor(10000 * 40 / 100) = 4000
C = 10000 - A - B       = 2000
```

最后一个接收方承担尾差，确保实际提交给汇付的金额总和严格等于订单金额，不能沿用现有“尾差归平台且不生成明细”的行为，除非产品确认平台确实是留存方。

手续费要单独建模：明确是从总额先扣后按比例分，还是由平台留存承担。页面显示的分账金额必须与汇付实际金额一致。

规则保存必须改为：

- Huifu 模式只接受已登记的三个 `huifu_id`，禁止前端任意传收款人名称/账号。
- 4:4:2 模式总比例必须等于 100%，不能沿用当前“总和小于等于 100%”的宽松校验。
- 比例、接收方映射和手续费策略生成不可变版本；新规则只影响新订单。
- 下单前调用汇付分账配置查询做能力校验，发现比例/接收方/产品不匹配直接阻止支付。

## 6. 交易时序与幂等

### 支付

1. 服务端根据真实业务订单计算应付金额，创建本地支付单和 4:4:2 快照。
2. 生成确定性的汇付商户订单号和请求序列号，写入 outbox，再调用汇付小程序支付。
3. 前端只拿汇付返回的调起参数，不接触私钥或分账参数。
4. 汇付回调必须先验签，再校验商户订单号、金额、租户、状态；只有最终支付成功才标记本地 `paid`。
5. 回调只做幂等入库和投递分账任务，不在回调事务内等待分账 HTTP 请求。
6. 回调丢失时由定时任务主动查单；“请求受理”不能当成支付成功。

### 分账

1. outbox worker claim 一条 `split_pending` 订单。
2. 以本地分账单号作为汇付幂等号，提交一次实时分账。
3. 保存汇付返回的受理号/分账单号和原始响应摘要。
4. 受理后进入 `split_processing`，由回调或查询收敛到 `split_success` / `split_failed` / `split_unknown`。
5. `split_unknown` 禁止盲目重提，必须先查询；只有确认汇付未受理才允许重试。

### 退款

```text
分账前退款：Huifu 原支付退款
分账后退款：按实际已分账接收方做参与方退款，再收敛业务退款
```

现有“退款后下期抵扣、不追回已分账”的策略不能直接沿用到实时分账；是否允许抵扣必须以汇付退款能力和财务规则确认。取消订单后才收到支付成功通知时，必须生成退款任务，不能简单把支付单标记 `paid` 后返回成功。

## 7. 管理端与前端

管理端需要提供：

- 汇付环境/产品状态和配置就绪检查（脱敏）；
- 三个接收方 `huifu_id` 的绑定、KYC 状态和启停；
- 4:4:2 规则预览、金额试算和汇付配置查询；
- 支付、分账、退款的状态、汇付单号、失败原因、最后查询时间；
- 仅授权管理员可执行重试/人工对账，并记录审计日志。

移动端将现有微信支付参数改为汇付小程序支付参数；业务订单状态仍通过 Chamber 查询，不让前端直接判断“支付成功即分账成功”。

## 8. work buddy 开发拆分

### PR-01：Provider 基础设施

- `HuifuConfig`：测试/生产隔离、密钥读取、证书轮换、超时和重试策略。
- `HuifuSigner`：RSA2 请求签名、回调验签、敏感日志脱敏。
- `HuifuHttpClient`：统一 envelope、`resp_code`、`resp_desc`、超时、连接异常和原始响应摘要。
- 增加 `huifu_live=false` feature flag，默认禁止真实出款。

### PR-02：支付域

- 新支付表/迁移、创建支付、幂等重试、回调、主动查单。
- 业务金额必须从服务端订单读取，不能信任客户端金额。
- 补齐支付成功、重复回调、金额不一致、回调丢失、网络超时测试。

### PR-03：接收方与规则

- 接收方映射、KYC 状态、规则版本和订单快照。
- 4:4:2 整数分计算、手续费、尾差、总额校验。
- 汇付配置查询和上线前 dry-run。

### PR-04：实时分账

- `createSplit/querySplit`、outbox worker、回调收敛、unknown 对账。
- Provider 受理态与最终成功态分离。
- 防重复提交、退避、人工重试和审计。

### PR-05：退款与异常闭环

- 分账前/后全额及部分退款。
- 取消后迟到支付的自动退款任务。
- 接收方余额不足、分账失败、退款失败的人工处理状态。

### PR-06：契约与上线门禁

- OpenAPI、前端支付适配、管理端页面、HTTP/DB acceptance。
- 测试商户小额真实交易、重复通知、主动查单、分账查询、部分退款和对账文件。
- 未通过所有验收前禁止将 `huifu_live` 打开。

## 9. 上线验收标准

必须逐项留证：

- 一笔测试订单支付金额与汇付订单金额一致；
- 支付通知重复 10 次只产生一个本地支付事实；
- 100.00 元准确分成 40.00 / 40.00 / 20.00，汇付三方账单可核对；
- 支付通知丢失时主动查单能补齐状态；
- 分账请求超时不会重复分账；
- 分账受理但未最终成功时不会显示“已完成”；
- 分账前、分账后全额/部分退款均能收敛；
- 接收方/KYC/比例不满足时支付前阻断；
- 生产密钥、原始回调和身份证/银行卡等敏感信息不出现在日志；
- 运行 `scripts/check-project.sh`、OpenAPI 校验、Chamber HTTP/DB acceptance 全部通过。

## 10. 当前仓库的直接结论

现有代码可复用“金额、租户、幂等、claim/lease、失败退避”的骨架，但有三处不能带入生产：

1. [SettlementChannelInterface.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/contracts/SettlementChannelInterface.php:11) 只有 `pay()`，必须扩展为支付/查询/分账/退款能力。
2. [WechatSplitChannel.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/services/WechatSplitChannel.php:26) 和 [BankTransferChannel.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/services/BankTransferChannel.php:22) 仍是未接入的 501 骨架。
3. [SettlementService.php](/Users/yanghy/Documents/Codex/mingde-vp-chamber/backend/custom/app/chamber/services/SettlementService.php:137) 允许调用方传入订单金额生成分账单；汇付接入时必须改为从已支付的真实业务订单读取金额，并以支付单事实作为唯一触发源。

现阶段 PR-01 基础设施已经完成。下一步顺序应调整为：先在汇付控台为实际收款商户申请/开通“多方分账”，为三个接收方完成进件和 KYC，并配置 Webhook；拿到开通结果和产品接口文档后，再完成 PR-02/PR-03 的商户绑定与支付适配，最后写 PR-04 的实时分账请求体。这样可以避免把其他汇付产品的字段误写进生产资金链路。

## 11. 当前实现进度

已落地 PR-01 的安全底座骨架：

- `HuifuConfig`：环境变量配置、脱敏就绪检查、`HUIFU_LIVE` 默认关闭；
- `HuifuSigner`：RSA-SHA256 签名/验签，canonical message 由产品 adapter 提供；
- `HuifuHttpClient`：HTTPS、超时、JSON 响应、HTTP/网络异常和响应摘要哈希。

这三个类暂不调用任何真实汇付 endpoint。拿到汇付产品合同后，再实现支付和分账 adapter，并把请求体字段写入对应测试用例。
