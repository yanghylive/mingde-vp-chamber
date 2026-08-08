<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\ProductExchangeService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class ProductExchangeController
{
    private const MAX_BODY_BYTES = 8192;
    private const MAX_ORDER_LIMIT = 50;

    /** @var ProductExchangeService */
    private $service;

    public function __construct(ProductExchangeService $service)
    {
        $this->service = $service;
    }

    public function exchange(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $product_id
    ): Response {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(
                400,
                'idempotency_key_required',
                'Idempotency-Key header is required'
            );
        }

        $body = $this->decodeJsonObject($request);
        $pointsCost = $body['points_cost'] ?? null;
        $cashCost = $body['cash_cost'] ?? '0.00';

        if (!is_int($pointsCost) && !(is_string($pointsCost) && preg_match('/^[1-9][0-9]*$/D', $pointsCost))) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'points_cost must be a positive integer',
                [['field' => 'points_cost', 'code' => 'invalid_value']]
            );
        }
        if (!is_string($cashCost) && !is_int($cashCost) && !is_float($cashCost)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'cash_cost must be a decimal',
                [['field' => 'cash_cost', 'code' => 'invalid_value']]
            );
        }

        $order = $this->service->exchange(
            $tenant,
            $auth,
            $this->positiveId($product_id),
            (int) $pointsCost,
            (string) $cashCost,
            $callerKey
        );

        return $this->success($order);
    }

    public function orders(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $limit = min(self::MAX_ORDER_LIMIT, max(1, (int) $request->get('limit', 20)));
        $page = max(1, (int) $request->get('page', 1));

        return $this->success($this->service->orders($tenant, $auth, $page, $limit));
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $id = (int) $value;
            if ((string) $id === $value) {
                return $id;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        throw new MemberTransactionException(422, 'request_validation_failed', 'product_id must be a positive integer');
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json' && substr($contentType, -5) !== '+json') {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Content-Type must be application/json'
            );
        }

        $raw = $request->getContent();
        if (!is_string($raw) || strlen($raw) > self::MAX_BODY_BYTES || trim($raw) === '') {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a JSON object of at most ' . self::MAX_BODY_BYTES . ' bytes'
            );
        }

        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a valid JSON object'
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a valid JSON object'
            );
        }

        return $decoded;
    }

    private function success(array $data): Response
    {
        return Response::create([
            'code' => 0,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
