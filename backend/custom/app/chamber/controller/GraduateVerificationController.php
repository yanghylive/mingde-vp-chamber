<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\GraduateVerificationService;
use app\chamber\tenancy\TenantContext;
use app\chamber\verification\GraduateVerificationSubmission;
use app\chamber\verification\GraduateVerificationValidationException;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class GraduateVerificationController
{
    /** @var GraduateVerificationService */
    private $service;

    public function __construct(GraduateVerificationService $service)
    {
        $this->service = $service;
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        unset($request);

        return $this->response(200, 'ok', $this->service->query($tenant, $auth));
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
            $submission = GraduateVerificationSubmission::fromArray($payload);
        } catch (GraduateVerificationValidationException $exception) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => $exception->field(), 'code' => $exception->fieldCode()]]
            );
        }

        $data = $this->service->submit(
            $tenant,
            $auth,
            $submission,
            $callerKey,
            ['correlation_id' => isset($request->correlationId) ? (string) $request->correlationId : '']
        );

        return $this->response(201, 'created', $data);
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json' && substr($contentType, -5) !== '+json') {
            throw new InvalidArgumentException('Content-Type must be application/json');
        }

        $raw = $request->getContent();
        if (!is_string($raw) || strlen($raw) > 32768 || trim($raw) === '') {
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

    private function response(int $status, string $message, array $data): Response
    {
        return Response::create([
            'status' => $status,
            'msg' => $message,
            'data' => $data,
        ], 'json', $status);
    }
}
