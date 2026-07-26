<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MembershipCheckoutService;
use app\chamber\tenancy\TenantContext;
use think\Response;

final class MembershipPlanController
{
    /** @var MembershipCheckoutService */
    private $service;

    public function __construct(MembershipCheckoutService $service)
    {
        $this->service = $service;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        unset($request);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $this->service->listPlans($tenant, $auth),
        ], 'json', 200);
    }
}
