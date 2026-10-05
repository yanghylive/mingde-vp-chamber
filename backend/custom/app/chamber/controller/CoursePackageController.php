<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\CourseConsumptionPolicy;
use app\chamber\services\CoursePackageService;
use app\chamber\tenancy\TenantContext;
use think\Response;

/** 课时包（会员端购买页）。 */
final class CoursePackageController
{
    /** @var CoursePackageService */
    private $service;

    public function __construct(CoursePackageService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, TenantContext $tenant): Response
    {
        $now = time();
        $page = $this->positiveIntQuery($request, 'page', 1, 1, 1000);
        $limit = $this->positiveIntQuery($request, 'limit', 20, 1, 100);
        $courseType = $this->optionalCourseType($request);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $this->service->list($tenant, $now, $page, $limit, $courseType),
        ], 'json', 200);
    }

    /** 可选 course_type 筛选（与 CourseConsumptionPolicy 枚举对齐）；空值表示不过滤。 */
    private function optionalCourseType(Request $request): ?string
    {
        $value = $request->get('course_type');
        if ($value === null || $value === '') {
            return null;
        }
        $allowed = [
            CourseConsumptionPolicy::TYPE_PRIVATE,
            CourseConsumptionPolicy::TYPE_GROUP,
            CourseConsumptionPolicy::TYPE_FAMILY,
            CourseConsumptionPolicy::TYPE_ONCOURSE,
        ];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'course_type is invalid',
                [['field' => 'course_type', 'code' => 'invalid_value']]
            );
        }

        return $value;
    }

    public function show(Request $request, TenantContext $tenant, $package_id): Response
    {
        unset($request);
        $pkg = $this->service->show($tenant, $this->positiveId($package_id, 'package_id'));
        if ($pkg === null) {
            throw new MemberTransactionException(404, 'course_package_not_found', '课时包不存在');
        }

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $pkg->toPublicArray(),
        ], 'json', 200);
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

    private function positiveIntQuery(Request $request, string $field, int $default, int $min, int $max): int
    {
        $value = $request->get($field);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' is out of range',
                [['field' => $field, 'code' => 'invalid_value']]
            );
        }

        return $value;
    }
}
