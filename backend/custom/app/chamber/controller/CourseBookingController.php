<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\activity\CourseBookingRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\CourseBookingService;
use app\chamber\tenancy\TenantContext;
use app\Request;
use InvalidArgumentException;
use stdClass;
use think\Response;

/** 约课（占座）+ 我的约课列表。 */
final class CourseBookingController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var CourseBookingService */
    private $service;

    public function __construct(CourseBookingService $service)
    {
        $this->service = $service;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $session_id
    ): Response {
        $callerKey = $this->idempotencyKey($request);
        $booking = CourseBookingRequest::fromArray($this->decodeJsonObject($request));

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $this->service->book(
                $tenant,
                $auth,
                $this->positiveId($session_id, 'session_id'),
                $booking,
                $callerKey
            ),
        ], 'json', 201);
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        unset($request);
        $page = $this->positiveIntQuery($request, 'page', 1, 1, 1000);
        $limit = $this->positiveIntQuery($request, 'limit', 20, 1, 100);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $this->service->listForMember($tenant, $auth, $page, $limit),
        ], 'json', 200);
    }

    private function idempotencyKey(Request $request): string
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
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' is out of range', [['field' => $field, 'code' => 'invalid_value']]);
        }

        return $value;
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
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a JSON object of at most 16384 bytes', [['field' => 'body', 'code' => 'invalid_value']]);
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
}
