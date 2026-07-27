<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MembershipEntitlementService;
use app\chamber\tenancy\TenantContext;
use think\Response;

final class MembershipSummaryController
{
    /** @var MembershipEntitlementService */
    private $service;

    public function __construct(MembershipEntitlementService $service)
    {
        $this->service = $service;
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        unset($request);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $this->service->summary($tenant->tenantId(), $tenant->channelId(), $auth->uid()),
        ], 'json', 200);
    }
}
