<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\GraduateVerificationService;
use app\chamber\tenancy\TenantContext;
use app\chamber\verification\GraduateVerificationReviewRequest;
use app\chamber\verification\GraduateVerificationValidationException;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class GraduateVerificationReviewController
{
    /** @var GraduateVerificationService */
    private $service;

    public function __construct(GraduateVerificationService $service)
    {
        $this->service = $service;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $application_id
    ): Response {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(
                400,
                'idempotency_key_required',
                'Idempotency-Key header is required'
            );
        }
        $applicationId = $this->positiveId($application_id);

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
            $review = GraduateVerificationReviewRequest::fromArray($payload);
        } catch (GraduateVerificationValidationException $exception) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => $exception->field(), 'code' => $exception->fieldCode()]]
            );
        }

        $data = $this->service->review(
            $tenant,
            $admin,
            $applicationId,
            $review,
            $callerKey,
            ['correlation_id' => isset($request->correlationId) ? (string) $request->correlationId : '']
        );

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $data], 'json', 200);
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
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
            'application_id must be a positive integer',
            [['field' => 'application_id', 'code' => 'invalid_value']]
        );
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
}
