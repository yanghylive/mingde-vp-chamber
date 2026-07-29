<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventCheckinRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventCheckinService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class EventCheckinController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var EventCheckinService */
    private $service;

    public function __construct(EventCheckinService $service)
    {
        $this->service = $service;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $event_id
    ): Response {
        $callerKey = $this->idempotencyKey($request);
        $checkin = EventCheckinRequest::fromArray($this->decodeJsonObject($request));

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $this->service->checkin(
                $tenant,
                $auth,
                $this->positiveId($event_id),
                $checkin,
                $callerKey
            ),
        ], 'json', 201);
    }

    private function idempotencyKey(Request $request): string
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

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                return $parsed;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            'event_id must be a positive integer',
            [['field' => 'event_id', 'code' => 'invalid_value']]
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
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a JSON object of at most 16384 bytes',
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
}
