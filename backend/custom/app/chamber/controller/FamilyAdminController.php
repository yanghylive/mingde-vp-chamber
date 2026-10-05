<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\FamilyService;
use app\chamber\tenancy\TenantContext;
use think\Response;

/** 家庭账户（管理端列表）。 */
final class FamilyAdminController
{
    /** @var FamilyService */
    private $service;

    public function __construct(FamilyService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $filters = [];
        $status = $request->get('status');
        if (is_string($status) && $status !== '') {
            $filters['status'] = $status;
        }

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $this->service->listForAdmin($tenant, $admin, $filters)], 'json', 200);
    }
}
