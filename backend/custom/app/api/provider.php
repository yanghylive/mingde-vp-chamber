<?php

use app\api\ApiExceptionHandle;
use app\chamber\services\GuardedStoreCartServices;
use app\chamber\services\GuardedStoreOrderCartInfoServices;
use app\chamber\services\GuardedStoreOrderCreateServices;
use app\chamber\services\GuardedStoreOrderRefundServices;
use app\chamber\services\GuardedStoreOrderServices;
use app\chamber\services\GuardedStoreOrderTakeServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;

return [
    'think\exception\Handle' => ApiExceptionHandle::class,
    StoreCartServices::class => GuardedStoreCartServices::class,
    StoreOrderCartInfoServices::class => GuardedStoreOrderCartInfoServices::class,
    StoreOrderCreateServices::class => GuardedStoreOrderCreateServices::class,
    StoreOrderRefundServices::class => GuardedStoreOrderRefundServices::class,
    StoreOrderServices::class => GuardedStoreOrderServices::class,
    StoreOrderTakeServices::class => GuardedStoreOrderTakeServices::class,
];
