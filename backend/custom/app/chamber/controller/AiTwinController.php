<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\AiTwinService;
use app\chamber\tenancy\TenantContext;
use think\Response;

/**
 * AI 智能分身（会员端/小程序）：
 *   GET  /api/chamber/v1/ai-twin/me              我的分身状态（大咖端）
 *   POST /api/chamber/v1/ai-twin/train           训练对话（大咖↔训练师，自动提炼记忆）
 *   GET  /api/chamber/v1/ai-twin/train/history   我的训练对话回放
 *   GET  /api/chamber/v1/ai-twin/:expert_member_id/profile  分身公开信息
 *   POST /api/chamber/v1/ai-twin/:expert_member_id/chat     与分身对话（扣积分）
 */
final class AiTwinController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var AiTwinService */
    private $twin;

    public function __construct(?AiTwinService $twin = null)
    {
        $this->twin = $twin ?: new AiTwinService();
    }

    /** 我的分身状态 */
    public function me(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->twin->myTwin($tenant, $auth)]);
    }

    /** 训练对话 */
    public function train(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        $body = $this->decodeJson($request);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return json(['code' => 400, 'msg' => '请输入要训练的内容']);
        }
        if (mb_strlen($message) > 2000) {
            return json(['code' => 400, 'msg' => '单条内容过长（限 2000 字）']);
        }

        $data = $this->twin->train($tenant, $auth, $message);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    /** 我的训练对话回放 */
    public function trainHistory(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);
        $member = (new \app\chamber\services\MemberIdentityService())->resolve($tenant, $auth);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $this->twin->trainHistory($tenant->tenantId(), (int) $member['id'])]]);
    }

    /** 分身公开信息 */
    public function profile(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth, $expert_member_id): Response
    {
        unset($request);
        $expertMemberId = $this->positiveId($expert_member_id);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->twin->twinProfile($tenant->tenantId(), $expertMemberId)]);
    }

    /** 与分身对话（扣积分） */
    public function chat(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth, $expert_member_id): Response
    {
        $expertMemberId = $this->positiveId($expert_member_id);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new MemberTransactionException(
                422,
                'idempotency_key_required',
                'Idempotency-Key header is required (same key on retry prevents double charging)'
            );
        }

        $body = $this->decodeJson($request);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return json(['code' => 400, 'msg' => '请输入消息内容']);
        }
        if (mb_strlen($message) > 2000) {
            return json(['code' => 400, 'msg' => '消息过长（限 2000 字）']);
        }

        $data = $this->twin->chat($tenant, $auth, $expertMemberId, $message, $idempotencyKey);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(413, 'payload_too_large', '请求体过大');
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }

    private function positiveId($value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            return (int) $value;
        }

        throw new MemberTransactionException(422, 'invalid_id', 'expert_member_id must be a positive integer');
    }
}
