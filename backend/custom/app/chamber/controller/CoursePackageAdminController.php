<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\CoursePackageAdminService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

/** 课时包管理（管理端）。 */
final class CoursePackageAdminController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var CoursePackageAdminService */
    private $service;

    public function __construct(CoursePackageAdminService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $filters = (array) $request->get();

        return $this->ok($this->service->listForAdmin($tenant, $admin, $filters));
    }

    public function show(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $package_id): Response
    {
        unset($request);
        $pkg = $this->service->detailForAdmin($tenant, $admin, $this->positiveId($package_id, 'package_id'));
        if ($pkg === null) {
            throw new MemberTransactionException(404, 'course_package_not_found', '课时包不存在');
        }

        return $this->ok($pkg);
    }

    public function store(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->created($this->service->create($tenant, $admin, $payload, $callerKey));
    }

    public function update(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $package_id): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->ok($this->service->update($tenant, $admin, $this->positiveId($package_id, 'package_id'), $payload, $callerKey));
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(400, 'idempotency_key_required', 'Idempotency-Key header is required');
        }
        try {
            return \app\chamber\membership\BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Idempotency-Key header is invalid', [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]);
        }
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

        throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be a positive integer', [['field' => $field, 'code' => 'invalid_value']]);
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json' && substr($contentType, -5) !== '+json') {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Content-Type must be application/json', [['field' => 'body', 'code' => 'invalid_value']]);
        }
        $raw = $request->getContent();
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a JSON object of at most ' . self::MAX_BODY_BYTES . ' bytes', [['field' => 'body', 'code' => 'invalid_value']]);
        }
        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a valid JSON object', [['field' => 'body', 'code' => 'invalid_value']]);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a valid JSON object', [['field' => 'body', 'code' => 'invalid_value']]);
        }

        return $payload;
    }

    private function ok(array $data): Response
    {
        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $data], 'json', 200);
    }

    private function created(array $data): Response
    {
        return Response::create(['status' => 201, 'msg' => 'created', 'data' => $data], 'json', 201);
    }
}
