<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;

/**
 * 会员等级门禁（商业化权限控制）
 * L1 免费：活动/月历/签到/AI 问答
 * L2 ¥1000/年：大咖线上预约、商城兑换、好友
 * L3 ¥5000/年：大咖线下预约、分销码权益、私享会
 * L4 认证：全功能 + 专属权益
 */
final class TierGuardService
{
    /**
     * 校验会员等级 >= requiredTier（含到期时间检查）
     * @param array $member ch_tenant_member 行
     * @param int $requiredTier 2 或 3
     * @param string $feature 功能名（错误提示用）
     */
    public function require(array $member, int $requiredTier, string $feature): void
    {
        $tier = (int) ($member['tier'] ?? 1);
        $expire = (int) ($member['tier_expire_time'] ?? 0);

        // L4 认证会员全通过
        if ($tier >= 4) {
            return;
        }

        // 等级不足
        if ($tier < $requiredTier) {
            throw new MemberTransactionException(
                403,
                'tier_required',
                sprintf('%s需要 L%d 及以上会员，当前为 L%d。请到会员中心开通。', $feature, $requiredTier, $tier)
            );
        }

        // L2/L3 到期检查（到期后视为 L1）
        if ($tier > 1 && $expire > 0 && $expire < time()) {
            throw new MemberTransactionException(
                403,
                'tier_expired',
                sprintf('%s需要 L%d 及以上会员，当前会员已到期，请到会员中心续费。', $feature, $requiredTier)
            );
        }
    }
}
