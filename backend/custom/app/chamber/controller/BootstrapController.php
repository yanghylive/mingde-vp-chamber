<?php

namespace app\chamber\controller;

use app\chamber\tenancy\TenantContext;
use think\Response;

final class BootstrapController
{
    public function index(TenantContext $context): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => [
                'tenant' => [
                    'id' => $context->tenantId(),
                    'slug' => $context->tenantSlug(),
                ],
                'channel' => [
                    'id' => $context->channelId(),
                    'code' => $context->channelSlug(),
                ],
                'context_source' => $context->source(),
                'api_version' => 'v1',
            ],
        ], 'json', 200);
    }
}
