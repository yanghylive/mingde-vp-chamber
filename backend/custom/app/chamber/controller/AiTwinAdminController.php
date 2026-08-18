<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\AiTwinService;
use app\chamber\services\KnowledgeFileParser;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use think\facade\Db;
use think\Response;

/**
 * AI 智能分身训练板块（admin）：
 *   GET    /api/chamber/admin/v1/ai-twins                    大咖列表+分身状态
 *   GET    /api/chamber/admin/v1/ai-twins/:member_id         分身配置详情
 *   PUT    /api/chamber/admin/v1/ai-twins/:member_id         保存人设配置
 *   GET    /api/chamber/admin/v1/ai-twins/:member_id/memories  记忆列表
 *   DELETE /api/chamber/admin/v1/ai-twins/:member_id/memories/:memory_id  删除记忆
 *   GET    /api/chamber/admin/v1/ai-twins/:member_id/chats   训练对话回放
 */
final class AiTwinAdminController
{
    private const MAX_BODY_BYTES = 16384;

    private const MENTOR_ROLES = ['mentor', 'coach', 'industry_leader'];

    /** @var AiTwinService */
    private $twin;

    public function __construct(?AiTwinService $twin = null)
    {
        $this->twin = $twin ?: new AiTwinService();
    }

    /** 大咖列表 + 分身状态 */
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();

        // 大咖角色（mentor/coach/industry_leader）
        $roleIds = Db::table('ch_persona_role')
            ->where('tenant_id', $tenantId)
            ->whereIn('code', self::MENTOR_ROLES)
            ->where('status', 1)
            ->where('is_del', 0)
            ->column('id');

        // 已有分身配置的会员（用于包含"已建分身但非大咖角色"的会员）
        $aiMemberIds = Db::table('ch_expert_ai')
            ->where('tenant_id', $tenantId)
            ->column('member_id');

        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('id', 'desc')
            ->limit(200);

        // 角色条件：大咖角色 或 已有分身配置
        $query->where(function ($q) use ($roleIds, $aiMemberIds) {
            if ($roleIds) {
                $q->whereIn('primary_role_id', $roleIds);
            }
            if ($aiMemberIds) {
                if ($roleIds) {
                    $q->whereOr('id', 'in', $aiMemberIds);
                } else {
                    $q->whereIn('id', $aiMemberIds);
                }
            }
        });

        $members = $query->field('id AS member_id')->select()->toArray();

        // 批量取分身配置
        $aiRows = Db::table('ch_expert_ai')
            ->where('tenant_id', $tenantId)
            ->whereIn('member_id', array_column($members, 'member_id'))
            ->select()
            ->toArray();
        $aiMap = [];
        foreach ($aiRows as $ai) {
            $aiMap[(int) $ai['member_id']] = $ai;
        }

        $items = [];
        foreach ($members as $m) {
            $memberId = (int) $m['member_id'];
            $ai = $aiMap[$memberId] ?? null;

            $profile = Db::table('ch_member_profile')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->find();
            $realName = is_array($profile) ? (string) ($profile['real_name'] ?? '') : '';
            $jobTitle = is_array($profile) ? (string) ($profile['job_title'] ?? '') : '';

            $personaName = is_array($ai) ? (string) ($ai['persona_name'] ?? '') : '';
            $items[] = [
                'member_id' => $memberId,
                'name' => $personaName !== '' ? $personaName : ($realName !== '' ? $realName : '大咖#' . $memberId),
                'real_name' => $realName,
                'job_title' => $jobTitle,
                'ai_id' => is_array($ai) ? (int) $ai['id'] : 0,
                'persona_role' => is_array($ai) ? (string) ($ai['persona_role'] ?? '') : '',
                'training_status' => is_array($ai) ? (int) ($ai['training_status'] ?? 0) : 0,
                'training_progress' => is_array($ai) ? (int) ($ai['training_progress'] ?? 0) : 0,
                'chat_points_cost' => is_array($ai) ? (int) ($ai['chat_points_cost'] ?? 20) : 20,
                'chat_count' => is_array($ai) ? (int) ($ai['chat_count'] ?? 0) : 0,
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 分身配置详情 */
    public function show(Request $request, TenantContext $tenant, $member_id): Response
    {
        unset($request);
        $memberId = (int) $member_id;
        $ai = $this->twin->ensureAi($tenant->tenantId(), $memberId);

        $memories = $this->twin->memories($tenant->tenantId(), $memberId);
        $readiness = $this->twin->twinReadiness($tenant->tenantId(), $memberId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'member_id' => $memberId,
            'ai_id' => (int) $ai['id'],
            'persona_name' => (string) $ai['persona_name'],
            'persona_role' => (string) $ai['persona_role'],
            'voice_style' => (string) $ai['voice_style'],
            'catchphrases' => (string) $ai['catchphrases'],
            'knowledge_base' => (string) $ai['knowledge_base'],
            'training_status' => (int) $ai['training_status'],
            'training_progress' => (int) $ai['training_progress'],
            'chat_points_cost' => (int) $ai['chat_points_cost'],
            'chat_count' => (int) $ai['chat_count'],
            'readiness' => $readiness,
            'memories' => $memories,
        ]]);
    }

    /** 保存人设配置（admin 手动编辑，与对话训练互补） */
    public function update(Request $request, TenantContext $tenant, $member_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        $memberId = (int) $member_id;
        $body = $this->decodeJson($request);
        $ai = $this->twin->ensureAi($tenant->tenantId(), $memberId);

        $data = [
            'persona_name' => trim((string) ($body['persona_name'] ?? $ai['persona_name'])),
            'persona_role' => trim((string) ($body['persona_role'] ?? $ai['persona_role'])),
            'voice_style' => trim((string) ($body['voice_style'] ?? $ai['voice_style'])),
            'catchphrases' => trim((string) ($body['catchphrases'] ?? $ai['catchphrases'])),
            'knowledge_base' => trim((string) ($body['knowledge_base'] ?? $ai['knowledge_base'])),
            'chat_points_cost' => max(1, (int) ($body['chat_points_cost'] ?? $ai['chat_points_cost'])),
            'update_time' => time(),
        ];

        Db::table('ch_expert_ai')->where('id', (int) $ai['id'])->update($data);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['updated' => true]]);
    }

    /** 上架/下架分身：上架后可被其他会员搜索/对话（对外商品维度，admin 审核） */
    public function setListed(Request $request, TenantContext $tenant, $member_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        $memberId = (int) $member_id;
        $body = $this->decodeJson($request);
        $listed = (int) ($body['is_listed'] ?? -1);
        if ($listed !== 0 && $listed !== 1) {
            return json(['code' => 400, 'msg' => 'is_listed 必须为 0 或 1']);
        }

        $ai = $this->twin->ensureAi($tenant->tenantId(), $memberId);
        if (!$ai) {
            return json(['code' => 404, 'msg' => '该会员尚未开通 AI 分身']);
        }

        Db::table('ch_expert_ai')->where('id', (int) $ai['id'])->update([
            'is_listed' => $listed,
            'update_time' => time(),
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['member_id' => $memberId, 'is_listed' => $listed === 1]]);
    }

    /** 记忆列表 */
    public function memories(Request $request, TenantContext $tenant, $member_id): Response
    {
        unset($request);
        $memberId = (int) $member_id;
        $items = $this->twin->memories($tenant->tenantId(), $memberId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 删除记忆 */
    public function deleteMemory(Request $request, TenantContext $tenant, $member_id, $memory_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        unset($request);
        $memberId = (int) $member_id;
        $memoryId = (int) $memory_id;
        $this->twin->deleteMemory($tenant->tenantId(), $memberId, $memoryId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => $memoryId]]);
    }

    /** 训练对话回放 */
    public function chats(Request $request, TenantContext $tenant, $member_id): Response
    {
        unset($request);
        $memberId = (int) $member_id;
        $items = $this->twin->trainHistory($tenant->tenantId(), $memberId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items]]);
    }

    /** 知识库列表 */
    public function knowledge(Request $request, TenantContext $tenant, $member_id): Response
    {
        unset($request);
        $memberId = (int) $member_id;
        $items = $this->twin->knowledgeList($tenant->tenantId(), $memberId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 新增知识条目 */
    public function addKnowledge(Request $request, TenantContext $tenant, $member_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        $memberId = (int) $member_id;
        $body = $this->decodeJson($request);
        if (isset($body['_too_large'])) {
            return json(['code' => 413, 'msg' => '请求体过大']);
        }

        $result = $this->twin->knowledgeAdd($tenant->tenantId(), $memberId, $body);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }

    /**
     * 上传文档解析为知识草稿（PDF/Word/TXT，multipart file 字段）。
     * 返回解析出的标题/内容，由前端确认后调 addKnowledge 入库（source=file, source_file=原文件名）。
     */
    public function uploadKnowledge(Request $request, TenantContext $tenant, $member_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        $memberId = (int) $member_id;
        $file = $request->file('file');
        if ($file === null) {
            return json(['code' => 422, 'msg' => '缺少文件（字段名 file）']);
        }

        $path = $file->getPathname();
        $origName = (string) $file->getOriginalName();

        try {
            $parsed = (new KnowledgeFileParser())->parse((string) $path, $origName);
        } catch (RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $parsed]);
    }

    /** 删除知识条目 */
    public function deleteKnowledge(Request $request, TenantContext $tenant, $member_id, $knowledge_id, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.ai_twin.write');
        unset($request);
        $memberId = (int) $member_id;
        $knowledgeId = (int) $knowledge_id;
        $this->twin->knowledgeDelete($tenant->tenantId(), $memberId, $knowledgeId);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => $knowledgeId]]);
    }

    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return ['_too_large' => true];
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }
}
