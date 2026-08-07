<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\coaching\CoachingService;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use think\Response;

/**
 * 小薇认知教练接口。
 * GET  /v1/coaching/today    今日卡片（首屏拉取）
 * POST /v1/coaching/morning  生成今日 3问+微优化+挑战（force 可重新生成）
 * POST /v1/coaching/respond  会员回传（回答+挑战结果，支持最低门槛）
 * POST /v1/coaching/evening  晚间复盘（force 可重新生成）
 * GET  /v1/coaching/status   断档/控速状态
 */
final class CoachingController
{
    /** @var CoachingService */
    private $service;

    public function __construct(CoachingService $service)
    {
        $this->service = $service;
    }

    public function today(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        return $this->success($this->service->today($tenant, $auth));
    }

    public function morning(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $force = $this->boolField($request, 'force', false);

        return $this->success($this->service->morning($tenant, $auth, $force));
    }

    public function respond(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        try {
            $input = $this->decodeJsonObject($request);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                $exception->getMessage()
            );
        }

        return $this->success($this->service->respond($tenant, $auth, $input));
    }

    public function evening(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $force = $this->boolField($request, 'force', false);

        return $this->success($this->service->evening($tenant, $auth, $force));
    }

    public function status(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        return $this->success($this->service->status($tenant, $auth));
    }

    private function success(array $data): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }

    private function boolField(Request $request, string $name, bool $default): bool
    {
        $raw = $request->post($name, $request->get($name, $default ? '1' : '0'));
        $raw = (string) $raw;

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function decodeJsonObject(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (trim($raw) === '') {
            throw new InvalidArgumentException('Request body must be a JSON object');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be a JSON object');
        }

        return $decoded;
    }
}
