<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\CourseBookingService;
use app\chamber\services\CourseSessionService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

/** 课次管理（排课 / 签发签到令牌 / 代签）。 */
final class CourseSessionAdminController
{
    private const MAX_BODY_BYTES = 524288;

    /** @var CourseSessionService */
    private $sessions;

    /** @var CourseBookingService */
    private $bookings;

    public function __construct(CourseSessionService $sessions, CourseBookingService $bookings)
    {
        $this->sessions = $sessions;
        $this->bookings = $bookings;
    }

    public function index(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $filters = $this->listFilters($request);

        return $this->ok($this->sessions->listForAdmin($tenant, $admin, $filters));
    }

    public function show(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $session_id): Response
    {
        unset($request);

        return $this->ok($this->sessions->detailForAdmin($tenant, $admin, $this->positiveId($session_id, 'session_id')));
    }

    public function store(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->created($this->sessions->create($tenant, $admin, $payload, $callerKey));
    }

    public function update(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $session_id): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->ok($this->sessions->update($tenant, $admin, $this->positiveId($session_id, 'session_id'), $payload, $callerKey));
    }

    public function checkinToken(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $session_id): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $this->assertAllowedFields($payload, ['ttl_seconds']);
        $ttl = $this->optionalInteger($payload, 'ttl_seconds', 300, 30, 3600);

        return $this->created($this->sessions->issueCheckinToken(
            $tenant,
            $admin,
            $this->positiveId($session_id, 'session_id'),
            $ttl,
            $callerKey
        ));
    }

    public function manualCheckin(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $session_id): Response
    {
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $this->assertAllowedFields($payload, ['booking_id', 'reason']);

        return $this->created($this->bookings->manualCheckin(
            $tenant,
            $admin,
            $this->positiveId($session_id, 'session_id'),
            $this->positiveId($payload['booking_id'] ?? null, 'booking_id'),
            $this->requiredString($payload, 'reason', 500),
            $callerKey
        ));
    }

    private function listFilters(Request $request): array
    {
        $filters = [];
        $courseType = $request->get('course_type');
        if (is_string($courseType) && $courseType !== '') {
            $filters['course_type'] = $courseType;
        }
        $status = $request->get('status');
        if (is_string($status) && $status !== '') {
            $filters['status'] = $status;
        }

        return $filters;
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(400, 'idempotency_key_required', 'Idempotency-Key header is required');
        }
        try {
            return BootstrapIdempotency::assertCallerKey($callerKey);
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
        if (!is_string($raw) || strlen($raw) > self::MAX_BODY_BYTES || trim($raw) === '') {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a JSON object of at most 524288 bytes', [['field' => 'body', 'code' => 'invalid_value']]);
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

    private function assertAllowedFields(array $payload, array $allowed): void
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'Request body contains an unknown field', [['field' => is_string($field) ? $field : 'body', 'code' => 'unknown_field']]);
            }
        }
    }

    private function optionalInteger(array $payload, string $field, int $default, int $min, int $max): int
    {
        if (!array_key_exists($field, $payload)) {
            return $default;
        }
        $value = $payload[$field];
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                $value = $integer;
            }
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' is out of range', [['field' => $field, 'code' => 'invalid_value']]);
        }

        return $value;
    }

    private function requiredString(array $payload, string $field, int $max): string
    {
        if (!array_key_exists($field, $payload) || !is_string($payload[$field])) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' is required', [['field' => $field, 'code' => 'required']]);
        }
        $value = trim($payload[$field]);
        if ($value === '' || strlen($value) > $max) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must contain 1 to ' . $max . ' characters', [['field' => $field, 'code' => 'invalid_length']]);
        }

        return $value;
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
