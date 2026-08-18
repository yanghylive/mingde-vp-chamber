<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\GraduateVerificationService;
use app\chamber\tenancy\TenantContext;
use app\chamber\verification\GraduateVerificationAdminQuery;
use app\chamber\verification\GraduateVerificationValidationException;
use think\Response;

final class GraduateVerificationAdminController
{
    /** @var GraduateVerificationService */
    private $service;

    public function __construct(GraduateVerificationService $service)
    {
        $this->service = $service;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin
    ): Response {
        $admin->assertPermission('chamber.graduate_verification.read');
        try {
            $query = GraduateVerificationAdminQuery::fromArray((array) $request->get());
        } catch (GraduateVerificationValidationException $exception) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => $exception->field(), 'code' => $exception->fieldCode()]]
            );
        }

        return $this->response($this->service->listForAdmin($tenant, $admin, $query));
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $application_id
    ): Response {
        unset($request);
        $admin->assertPermission('chamber.graduate_verification.read');

        return $this->response($this->service->detailForAdmin(
            $tenant,
            $admin,
            $this->positiveId($application_id)
        ));
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                return $integer;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            'application_id must be a positive integer',
            [['field' => 'application_id', 'code' => 'invalid_value']]
        );
    }

    private function response(array $data): Response
    {
        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $data], 'json', 200);
    }
}
