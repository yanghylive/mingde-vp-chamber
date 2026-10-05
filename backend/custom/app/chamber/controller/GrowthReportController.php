<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\CoachSessionReportService;
use app\chamber\tenancy\TenantContext;
use app\Request;
use think\Response;

/** 学员成长档案（会员态读取，解锁 M10 成长改变展示）。 */
final class GrowthReportController
{
    /** @var CoachSessionReportService */
    private $service;

    public function __construct(CoachSessionReportService $service)
    {
        $this->service = $service;
    }

    public function show(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);
        $data = $this->service->growthForMember($tenant, $auth);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
