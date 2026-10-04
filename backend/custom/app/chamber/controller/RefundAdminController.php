<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventRefundRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\EventRegistrationRefundService;
use app\chamber\tenancy\TenantContext;
use stdClass;
use think\Response;

/** 活动退票管理：退款单查询与人工财务确认（租户隔离 + 动作级权限）。 */
final class RefundAdminController
{
    private const MAX_BODY_BYTES = 524288;

    /** @var EventRegistrationRefundService */
    private $refunds;

    public function __construct(EventRegistrationRefundService $refunds = null)
    {
        $this->refunds = $refunds ?: new EventRegistrationRefundService();
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin
    ): Response {
        $admin->assertPermission('chamber.refund.read');

        return $this->ok($this->refunds->listAttemptsForAdmin($tenant, (array) $request->get()));
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $attempt_id
    ): Response {
        unset($request);
        $admin->assertPermission('chamber.refund.read');

        return $this->ok($this->refunds->attemptDetailForAdmin(
            $tenant,
            $this->positiveId($attempt_id, 'attempt_id')
        ));
    }

    public function confirm(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $attempt_id
    ): Response {
        $admin->assertPermission('chamber.refund.confirm');
        $body = EventRefundRequest::fromArray($this->decodeJsonObject($request));

        return $this->ok($this->refunds->confirmManually(
            $tenant,
            $admin,
            $this->positiveId($attempt_id, 'attempt_id'),
            $body->reason()
        ));
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

    private function ok(array $data): Response
    {
        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $data], 'json', 200);
    }
}
