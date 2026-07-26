<?php

use app\ExceptionHandle;
use app\Request;
use app\chamber\services\GuardedStoreOrderCartInfoServices;
use app\chamber\services\GuardedStoreOrderRefundServices;
use app\chamber\services\GuardedStoreOrderServices;
use app\chamber\services\GuardedStoreOrderTakeServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;

// This is the root provider, so the same boundaries apply to HTTP, queue,
// timer and CLI applications. The API provider adds the API exception handler.
return [
    'think\Request' => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
    // Order-create service stays native here because the trusted Chamber
    // gateway uses them to assemble its private checkout cache. Order reads,
    // line-item reads, refunds and post-take side effects remain guarded in
    // every process.
    StoreOrderCartInfoServices::class => GuardedStoreOrderCartInfoServices::class,
    StoreOrderRefundServices::class => GuardedStoreOrderRefundServices::class,
    StoreOrderServices::class => GuardedStoreOrderServices::class,
    StoreOrderTakeServices::class => GuardedStoreOrderTakeServices::class,
];
