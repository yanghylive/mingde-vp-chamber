<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\activity\CourseSessionListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\services\CourseSessionService;
use app\chamber\tenancy\TenantContext;
use app\Request;
use think\Response;

/** 可约课次（会员端约课页）。 */
final class CourseSessionController
{
    /** @var CourseSessionService */
    private $service;

    public function __construct(CourseSessionService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, TenantContext $tenant): Response
    {
        $now = time();
        $list = $this->service->list($tenant, CourseSessionListQuery::fromArray((array) $request->get()), $now);

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $list], 'json', 200);
    }

    public function show(Request $request, TenantContext $tenant, $session_id): Response
    {
        unset($request);
        $snapshot = $this->service->show($tenant, $this->positiveId($session_id, 'session_id'));
        if ($snapshot === null) {
            throw new MemberTransactionException(404, 'course_session_not_found', '课次不存在');
        }

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $snapshot->toPublicArray()], 'json', 200);
    }

    private function positiveId($value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
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
            $field . ' must be a positive integer',
            [['field' => $field, 'code' => 'invalid_value']]
        );
    }
}
