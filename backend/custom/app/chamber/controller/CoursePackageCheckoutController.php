<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\CoursePackageCheckoutRequest;
use app\chamber\services\CoursePackageCheckoutService;
use app\chamber\services\CoursePackagePaymentCompletionService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

/** 购买课时包（会员端，需 Idempotency-Key）。 */
final class CoursePackageCheckoutController
{
    /** @var CoursePackageCheckoutService */
    private $service;

    /** @var CoursePackagePaymentCompletionService */
    private $completion;

    public function __construct(
        CoursePackageCheckoutService $service,
        CoursePackagePaymentCompletionService $completion = null
    ) {
        $this->service = $service;
        $this->completion = $completion ?: new CoursePackagePaymentCompletionService();
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $package_id
    ): Response {
        $callerKey = $this->idempotencyKey($request);
        $payload = $this->decodeJsonObject($request);
        $payload['package_id'] = $this->positiveId($package_id, 'package_id');

        try {
            $checkout = CoursePackageCheckoutRequest::fromArray($payload);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        $data = $this->service->checkout($tenant, $auth, $checkout, $callerKey);

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $data,
        ], 'json', 201);
    }

    /**
     * 模拟支付完成（开发环境）：凭 checkouts 返回的 context_no 触发授时。
     * 生产环境应由 CRMEB 订单支付成功钩子完成，本端点仅开发期验证使用
     * （服务内部已按 APP_ENV / CHAMBER_DEV_MOCK_PAY 开关拦截）。
     */
    public function complete(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $callerKey = $this->idempotencyKey($request);
        $payload = $this->decodeJsonObject($request);

        $contextNo = isset($payload['context_no']) && is_string($payload['context_no'])
            ? trim($payload['context_no']) : '';
        if ($contextNo === '') {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'context_no is required',
                [['field' => 'context_no', 'code' => 'required']]
            );
        }
        $payType = isset($payload['pay_type']) && is_string($payload['pay_type'])
            ? trim($payload['pay_type']) : 'yue';

        $data = $this->completion->complete($tenant, $auth, $contextNo, $payType);

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
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
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > 32768) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a JSON object of at most 32768 bytes',
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
