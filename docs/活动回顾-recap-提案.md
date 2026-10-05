# 活动回顾（recap）产品提案

> 状态：提案待确认。G2 剩余的最后一个功能缺口（退款/候补/管理写验收均已完成）。
> 原则：首版只做**只读聚合**，全部数据来自已有表，**不新增迁移**。

## 1. 为什么首版只做聚合

回顾所需的全部事实库里都有：

| 回顾项 | 数据来源 |
|---|---|
| 报名总数 / 各票种报名 | `ch_event_registration` 按 status/ticket 聚合 |
| 签到率 / 签到人数 | `ch_event_checkin` |
| 积分发放 / 贡献值 | `ch_event_reward`、`ch_contribution_ledger` |
| 退款 / 冲正 | `ch_event_registration_effect`、`ch_refund_attempt` |
| 候补转正数 | `ch_event_waitlist`（status=converted） |
| 活动基本信息 | `ch_event`、`ch_event_ticket` |

照片墙、回顾长文、管理员点评都需要**新表 + 上传链路 + 审核**，建议二期。

## 2. 接口设计（提案）

- `GET /chamber/v1/events/{event_id}/recap`（会员端，需报名或活动已结束；未开始返回 409）
- `GET /chamber/admin/v1/events/{event_id}/recap`（管理端，多运营字段：收入、退款金额、渠道维度）

响应只读、可缓存；与现有 `ResponseEnvelopeBase` 对齐，进 OpenAPI（0.9.0→0.10.0）。

## 3. 需要确认的问题

1. **可见性**：未报名的会员能看回顾吗？（建议：活动结束后公开，可传播拉新）
2. **贡献榜**：回顾页是否展示 Top 贡献/签到榜？（涉及会员昵称公开，需隐私确认）
3. **二期照片墙**：要做吗？要的话需要定上传、审核、存储方案。

确认后实现约 0.5 天（含契约 + DB 断言 + 门禁）。
