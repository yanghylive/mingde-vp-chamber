<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventAdminService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class EventAdminController
{
    private const MAX_BODY_BYTES = 524288;

    /** @var EventAdminService */
    private $service;

    public function __construct(EventAdminService $service)
    {
        $this->service = $service;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin
    ): Response {
        $admin->assertPermission('chamber.event.read');
        $filters = EventListQuery::fromArray((array) $request->get());

        return $this->ok($this->service->listForAdmin($tenant, $admin, $filters));
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
        unset($request);
        $admin->assertPermission('chamber.event.read');

        return $this->ok($this->service->detailForAdmin(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id')
        ));
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin
    ): Response {
        $admin->assertPermission('chamber.event.write');
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->created($this->service->create($tenant, $admin, $payload, $callerKey));
    }

    public function update(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
                $admin->assertPermission('chamber.event.write');
$callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        return $this->ok($this->service->update(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id'),
            $payload,
            $callerKey
        ));
    }

    public function publish(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
        $admin->assertPermission('chamber.event.manage');
        $callerKey = $this->requireIdempotencyKey($request);

        return $this->ok($this->service->publish(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id'),
            $callerKey
        ));
    }

    public function cancel(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
                $admin->assertPermission('chamber.event.manage');
$callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $this->assertAllowedFields($payload, ['reason']);
        $reason = $this->optionalString($payload, 'reason', 500);

        return $this->ok($this->service->cancel(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id'),
            $reason,
            $callerKey
        ));
    }

    public function checkinToken(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
        $admin->assertPermission('chamber.event.checkin');
        $callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $this->assertAllowedFields($payload, ['ttl_seconds']);
        $ttl = $this->optionalInteger($payload, 'ttl_seconds', 300, 30, 3600);

        return $this->created($this->service->issueCheckinToken(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id'),
            $ttl,
            $callerKey
        ));
    }

    public function manualCheckin(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $event_id
    ): Response {
                $admin->assertPermission('chamber.event.checkin');
$callerKey = $this->requireIdempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $this->assertAllowedFields($payload, ['registration_id', 'reason']);

        return $this->created($this->service->manualCheckin(
            $tenant,
            $admin,
            $this->positiveId($event_id, 'event_id'),
            $this->positiveId($payload['registration_id'] ?? null, 'registration_id'),
            $this->requiredString($payload, 'reason', 500),
            $callerKey
        ));
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(
                400,
                'idempotency_key_required',
                'Idempotency-Key header is required'
            );
        }
        try {
            return BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key header is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
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

        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            $field . ' must be a positive integer',
            [['field' => $field, 'code' => 'invalid_value']]
        );
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json' && substr($contentType, -5) !== '+json') {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Content-Type must be application/json',
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        $raw = $request->getContent();
        if (!is_string($raw) || strlen($raw) > self::MAX_BODY_BYTES || trim($raw) === '') {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a JSON object of at most 524288 bytes',
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }
        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a valid JSON object',
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a valid JSON object',
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        return $payload;
    }

    private function assertAllowedFields(array $payload, array $allowed): void
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new MemberTransactionException(
                    422,
                    'request_validation_failed',
                    'Request body contains an unknown field',
                    [['field' => is_string($field) ? $field : 'body', 'code' => 'unknown_field']]
                );
            }
        }
    }

    private function optionalString(array $payload, string $field, int $max): string
    {
        if (!array_key_exists($field, $payload)) {
            return '';
        }
        if (!is_string($payload[$field])) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' must be a string',
                [['field' => $field, 'code' => 'invalid_type']]
            );
        }
        $value = trim($payload[$field]);
        if (strlen($value) > $max) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' is too long',
                [['field' => $field, 'code' => 'invalid_length']]
            );
        }

        return $value;
    }

    private function requiredString(array $payload, string $field, int $max): string
    {
        $value = $this->optionalString($payload, $field, $max);
        if ($value === '') {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' is required',
                [['field' => $field, 'code' => 'required']]
            );
        }

        return $value;
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
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' is out of range',
                [['field' => $field, 'code' => 'invalid_value']]
            );
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
