<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\activity\CoachSessionReportRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\services\CoachSessionReportService;
use app\chamber\tenancy\TenantContext;
use app\Request;
use think\Response;

/** 教练每课填报（写入，admin/教练态）。 */
final class CoachSessionReportAdminController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var CoachSessionReportService */
    private $service;

    public function __construct(CoachSessionReportService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request, TenantContext $tenant): Response
    {
        $payload = $this->decodeJsonObject($request);
        // 教练身份：当前以请求体 coach_id 传入；与 admin token 的绑定方式待杨总确认（见契约文档 docs/backend）。
        $coachId = $this->positiveId($payload['coach_id'] ?? null, 'coach_id');
        $report = CoachSessionReportRequest::fromArray($payload);

        $result = $this->service->create($tenant, $coachId, $report);

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $result,
        ], 'json', 201);
    }

    /**
     * @param mixed $value
     */
    private function positiveId($value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $field . ' must be a positive integer',
                [['field' => $field, 'code' => 'invalid_value']]
            );
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
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
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(400, 'request_validation_failed', 'Request body must be a valid JSON object', [['field' => 'body', 'code' => 'invalid_value']]);
        }

        return $payload;
    }
}
