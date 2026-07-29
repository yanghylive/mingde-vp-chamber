<?php

declare(strict_types=1);

namespace app\chamber\jobs;

use app\chamber\contracts\EventOrderGatewayInterface;
use app\chamber\services\EventRegistrationCommerceProjection;
use app\chamber\services\EventReservationRepairService;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;
use think\facade\Log;

final class EventReservationRepairJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($limit = 50): bool
    {
        $limit = is_int($limit) ? $limit : (int) $limit;
        $summary = (new EventReservationRepairService(
            app()->make(EventOrderGatewayInterface::class)
        ))->releaseExpired($limit);
        $summary['events'] = app()->make(EventRegistrationCommerceProjection::class)
            ->consumePending($limit);
        Log::info('chamber.event_reservation_repair', $summary);

        return (int) $summary['failed'] === 0 && (int) $summary['events']['failed'] === 0;
    }
}
