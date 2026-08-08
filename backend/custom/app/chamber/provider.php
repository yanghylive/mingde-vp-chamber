<?php

use app\chamber\ChamberExceptionHandle;
use app\chamber\contracts\ReplayGuardInterface;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\contracts\EventRefundGatewayInterface;
use app\chamber\contracts\MembershipOrderGatewayInterface;
use app\chamber\contracts\EventOrderGatewayInterface;
use app\chamber\contracts\SignedTenantRequestVerifierInterface;
use app\chamber\contracts\TenantDirectoryInterface;
use app\chamber\services\CrmebMembershipOrderGateway;
use app\chamber\services\CrmebEventOrderGateway;
use app\chamber\services\CrmebEventRefundGateway;
use app\chamber\services\DisabledSignedTenantRequestVerifier;
use app\chamber\services\ConsentDocumentRegistry;
use app\chamber\services\HmacTenantRequestVerifier;
use app\chamber\services\RedisReplayGuard;
use app\chamber\services\TenantRuntimeConfig;
use app\chamber\services\ThinkDbTenantDirectory;
use app\chamber\services\ThinkDbCommerceEventStore;
use think\facade\Config;

return [
    'think\exception\Handle' => ChamberExceptionHandle::class,
    ConsentDocumentRegistry::class => function () {
        return new ConsentDocumentRegistry((array) Config::get('consent', []));
    },
    TenantRuntimeConfig::class => function () {
        return new TenantRuntimeConfig((array) Config::get('tenant', []));
    },
    TenantDirectoryInterface::class => function (TenantRuntimeConfig $runtimeConfig) {
        return new ThinkDbTenantDirectory($runtimeConfig->hostMappings());
    },
    ReplayGuardInterface::class => function (TenantRuntimeConfig $runtimeConfig) {
        return new RedisReplayGuard($runtimeConfig->replayPrefix());
    },
    MembershipOrderGatewayInterface::class => function () {
        return app()->make(CrmebMembershipOrderGateway::class);
    },
    EventOrderGatewayInterface::class => function () {
        return app()->make(CrmebEventOrderGateway::class);
    },
    EventRefundGatewayInterface::class => function () {
        return app()->make(CrmebEventRefundGateway::class);
    },
    CommerceEventStoreInterface::class => ThinkDbCommerceEventStore::class,
    SignedTenantRequestVerifierInterface::class => function (
        TenantRuntimeConfig $runtimeConfig,
        ReplayGuardInterface $replayGuard
    ) {
        $secret = $runtimeConfig->signingSecret();
        if ($secret === '') {
            return new DisabledSignedTenantRequestVerifier();
        }

        return new HmacTenantRequestVerifier(
            $secret,
            $replayGuard,
            $runtimeConfig->signatureTtl()
        );
    },
];
