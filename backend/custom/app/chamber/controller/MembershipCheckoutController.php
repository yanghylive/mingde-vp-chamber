<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\MembershipCheckoutRequest;
use app\chamber\services\MembershipCheckoutService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use think\Response;

final class MembershipCheckoutController
{
    /** @var MembershipCheckoutService */
    private $service;

    public function __construct(MembershipCheckoutService $service)
    {
        $this->service = $service;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(
                400,
                'idempotency_key_required',
                'Idempotency-Key header is required'
            );
        }
        try {
            BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key header is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }

        try {
            $payload = $this->decodeJsonObject($request);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }
        try {
            $checkout = MembershipCheckoutRequest::fromArray($payload);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        $data = $this->service->checkout(
            $tenant,
            $auth,
            $checkout,
            $callerKey,
            $this->authenticatedUser($request, $auth)
        );

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $data,
        ], 'json', 201);
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json') {
            throw new InvalidArgumentException('Content-Type must be application/json');
        }

        $raw = $request->getContent();
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > 32768) {
            throw new InvalidArgumentException('Request body must be a JSON object of at most 32768 bytes');
        }
        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }

        return $payload;
    }

    private function authenticatedUser(Request $request, AuthenticatedUserContext $auth): array
    {
        $user = $request->user();
        if (is_array($user)) {
            $authenticatedUser = $user;
        } elseif (is_object($user) && method_exists($user, 'toArray')) {
            $authenticatedUser = $user->toArray();
        } else {
            throw new RuntimeException('Authenticated CRMEB user is unavailable');
        }
        if (!is_array($authenticatedUser)) {
            throw new RuntimeException('Authenticated CRMEB user is invalid');
        }

        $uid = $authenticatedUser['uid'] ?? null;
        if (is_string($uid) && preg_match('/^[1-9][0-9]*$/D', $uid) === 1) {
            $parsed = (int) $uid;
            if ((string) $parsed === $uid) {
                $uid = $parsed;
            }
        }
        if (!is_int($uid) || $uid !== $auth->uid()) {
            throw new RuntimeException('Authenticated CRMEB user identity is inconsistent');
        }

        return $authenticatedUser;
    }
}
