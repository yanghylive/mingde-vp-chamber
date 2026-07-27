<?php

use app\api\ApiExceptionHandle;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\services\GuardedStoreCartServices;
use app\chamber\services\GuardedOutStoreOrderServices;
use app\chamber\services\GuardedStoreOrderCartInfoServices;
use app\chamber\services\GuardedStoreOrderCreateServices;
use app\chamber\services\GuardedStoreOrderDeliveryServices;
use app\chamber\services\GuardedStoreOrderRefundServices;
use app\chamber\services\GuardedStoreOrderSuccessServices;
use app\chamber\services\GuardedStoreOrderServices;
use app\chamber\services\GuardedStoreOrderTakeServices;
use app\chamber\services\ThinkDbCommerceEventStore;
use app\services\order\OutStoreOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;

return [
    'think\exception\Handle' => ApiExceptionHandle::class,
    CommerceEventStoreInterface::class => ThinkDbCommerceEventStore::class,
    StoreCartServices::class => GuardedStoreCartServices::class,
    StoreOrderCartInfoServices::class => GuardedStoreOrderCartInfoServices::class,
    StoreOrderCreateServices::class => GuardedStoreOrderCreateServices::class,
    StoreOrderDeliveryServices::class => GuardedStoreOrderDeliveryServices::class,
    StoreOrderRefundServices::class => GuardedStoreOrderRefundServices::class,
    StoreOrderSuccessServices::class => GuardedStoreOrderSuccessServices::class,
    StoreOrderServices::class => GuardedStoreOrderServices::class,
    StoreOrderTakeServices::class => GuardedStoreOrderTakeServices::class,
    OutStoreOrderServices::class => GuardedOutStoreOrderServices::class,
];
