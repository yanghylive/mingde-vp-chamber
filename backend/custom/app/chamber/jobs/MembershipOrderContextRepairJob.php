<?php

declare(strict_types=1);

namespace app\chamber\jobs;

use app\chamber\services\MembershipCheckoutService;
use app\chamber\services\CrmebMembershipOrderGateway;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;
use think\facade\Log;

final class MembershipOrderContextRepairJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($limit = 50): bool
    {
        $parsedLimit = is_int($limit) ? $limit : (int) $limit;
        $service = new MembershipCheckoutService(app()->make(CrmebMembershipOrderGateway::class));
        $summary = $service->reconcilePending($parsedLimit);
        Log::info('chamber.membership_order_context_repair', $summary);

        return (int) $summary['failed'] === 0;
    }
}
