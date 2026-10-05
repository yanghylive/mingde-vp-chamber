<?php

declare(strict_types=1);

namespace app\chamber\jobs;

use app\chamber\services\CreditExpiryJob as CreditExpiryService;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;
use think\facade\Db;
use think\facade\Log;

/** 定时任务：遍历所有启用租户，作废到期未用的课时授予并冲减余额。 */
final class CreditExpiryJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($limit = 200): bool
    {
        $limit = is_int($limit) ? $limit : (int) $limit;
        $tenantIds = Db::table('ch_tenant')->where('status', 1)->where('is_del', 0)->column('id');
        $ok = true;
        foreach ($tenantIds as $tenantId) {
            $summary = (new CreditExpiryService())->run((int) $tenantId, $limit);
            Log::info('chamber.credit_expiry', ['tenant_id' => $tenantId] + $summary);
            if ((int) ($summary['failed'] ?? 0) > 0) {
                $ok = false;
            }
        }

        return $ok;
    }
}
