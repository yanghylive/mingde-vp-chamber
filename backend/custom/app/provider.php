<?php

use app\ExceptionHandle;
use app\Request;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\services\GuardedStoreOrderCartInfoServices;
use app\chamber\services\GuardedOutStoreOrderServices;
use app\chamber\services\GuardedStoreOrderDeliveryServices;
use app\chamber\services\GuardedStoreOrderRefundServices;
use app\chamber\services\GuardedStoreOrderSuccessServices;
use app\chamber\services\GuardedStoreOrderServices;
use app\chamber\services\GuardedStoreOrderTakeServices;
use app\chamber\services\ThinkDbCommerceEventStore;
use app\services\order\OutStoreOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;

// This is the root provider, so the same boundaries apply to HTTP, queue,
// timer and CLI applications. The API provider adds the API exception handler.
return [
    'think\Request' => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
    CommerceEventStoreInterface::class => ThinkDbCommerceEventStore::class,
    // Order-create service stays native here because the trusted Chamber
    // gateway uses them to assemble its private checkout cache. Order reads,
    // line-item reads, refunds and post-take side effects remain guarded in
    // every process.
    StoreOrderCartInfoServices::class => GuardedStoreOrderCartInfoServices::class,
    StoreOrderDeliveryServices::class => GuardedStoreOrderDeliveryServices::class,
    StoreOrderRefundServices::class => GuardedStoreOrderRefundServices::class,
    StoreOrderSuccessServices::class => GuardedStoreOrderSuccessServices::class,
    StoreOrderServices::class => GuardedStoreOrderServices::class,
    StoreOrderTakeServices::class => GuardedStoreOrderTakeServices::class,
    OutStoreOrderServices::class => GuardedOutStoreOrderServices::class,
];
