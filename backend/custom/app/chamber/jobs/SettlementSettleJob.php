<?php

declare(strict_types=1);

namespace app\chamber\jobs;

use app\chamber\services\SettlementService;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;
use think\facade\Log;

/**
 * 分账 T+1 结算任务：扫待结算明细，按通道打款。
 * 由 repair.php CLI（crontab 定时）直接调用 doJob，不依赖常驻队列 worker。
 */
final class SettlementSettleJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($limit = 50): bool
    {
        $limit = is_int($limit) ? $limit : (int) $limit;
        $summary = (new SettlementService())->runDue($limit);
        Log::info('chamber.settlement_run_due', $summary);

        return (int) $summary['failed'] === 0;
    }
}
