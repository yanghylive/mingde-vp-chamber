<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\activity\EventRegistrationListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventRegistrationService;
use app\chamber\services\EventService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use think\Response;

final class EventRegistrationController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var EventService */
    private $service;

    /** @var EventRegistrationService */
    private $registrations;

    public function __construct(EventService $service, EventRegistrationService $registrations)
    {
        $this->service = $service;
        $this->registrations = $registrations;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $event_id
    ): Response {
        $callerKey = $this->idempotencyKey($request);
        $registration = EventRegistrationRequest::fromArray($this->decodeJsonObject($request));

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $this->registrations->register(
                $tenant,
                $auth,
                $this->positiveId($event_id, 'event_id'),
                $registration,
                $callerKey,
                $this->authenticatedUser($request, $auth)
            ),
        ], 'json', 201);
    }

    private function authenticatedUser(Request $request, AuthenticatedUserContext $auth): array
    {
        $user = $request->user();
        $authenticatedUser = is_array($user)
            ? $user
            : (is_object($user) && method_exists($user, 'toArray') ? $user->toArray() : null);
        if (!is_array($authenticatedUser)) {
            throw new RuntimeException('Authenticated CRMEB user is unavailable');
        }
        $uid = $authenticatedUser['uid'] ?? null;
        if (is_string($uid) && preg_match('/^[1-9][0-9]*$/D', $uid) === 1) {
            $uid = (int) $uid;
        }
        if (!is_int($uid) || $uid !== $auth->uid()) {
            throw new RuntimeException('Authenticated CRMEB user identity is inconsistent');
        }

        return $authenticatedUser;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $filters = EventRegistrationListQuery::fromArray((array) $request->get());

        return $this->response($this->service->listRegistrations($tenant, $auth, $filters));
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $registration_id
    ): Response {
        unset($request);

        return $this->response($this->service->registrationDetail(
            $tenant,
            $auth,
            $this->positiveId($registration_id, 'registration_id')
        ));
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

    private function positiveId($value, string $field): int
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

    private function response(array $data): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
