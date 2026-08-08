<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\facade\Db;
use think\Response;

/**
 * 大咖详情、档期与预约。定价字段暂用统一默认值（online/offline 双轨），
 * 后续可将定价改为 ch_expert_slot 或独立配置表管理。
 */
final class ExpertScheduleController
{
    private const MAX_BODY_BYTES = 8192;

    /** 统一默认定价：线上/线下 积分与现金 */
    private const ONLINE_POINTS = 100;
    private const ONLINE_CASH = '0.00';
    private const OFFLINE_POINTS = 200;
    private const OFFLINE_CASH = '0.00';

    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $expert_id
    ): Response {
        $expertId = $this->positiveId($expert_id);
        $expert = $this->findExpert($tenant->tenantId(), $expertId);
        if ($expert === null) {
            throw new MemberTransactionException(404, 'expert_not_found', 'Expert was not found');
        }

        $expert['pricing'] = [
            'online_points' => self::ONLINE_POINTS,
            'online_cash' => self::ONLINE_CASH,
            'offline_points' => self::OFFLINE_POINTS,
            'offline_cash' => self::OFFLINE_CASH,
        ];

        return $this->success($expert);
    }

    public function slots(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $expert_id
    ): Response {
        $expertId = $this->positiveId($expert_id);
        $now = time();

        $rows = Db::table('ch_expert_slot')
            ->where('tenant_id', $tenant->tenantId())
            ->where('expert_id', $expertId)
            ->where('status', 'open')
            ->where('start_time', '>=', $now)
            ->order('start_time', 'asc')
            ->limit(60)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'expert_id' => (int) $row['expert_id'],
                'start_time' => (int) $row['start_time'],
                'end_time' => (int) $row['end_time'],
                'status' => (string) $row['status'],
                'location' => (string) $row['location'],
            ];
        }

        return $this->success(['items' => $items]);
    }

    public function appointments(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $expert_id
    ): Response {
        $expertId = $this->positiveId($expert_id);
        $body = $this->decodeJsonObject($request);
        $slotId = $body['slot_id'] ?? null;
        $mode = $body['mode'] ?? '';

        if (!is_int($slotId) && !(is_string($slotId) && preg_match('/^[1-9][0-9]*$/D', $slotId))) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'slot_id must be a positive integer',
                [['field' => 'slot_id', 'code' => 'invalid_value']]
            );
        }
        if (!is_string($mode) || !in_array($mode, ['online', 'offline'], true)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'mode must be online or offline',
                [['field' => 'mode', 'code' => 'invalid_value']]
            );
        }

        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $now = time();

        $appointment = Db::transaction(function () use (
            $tenantId,
            $expertId,
            $member,
            $slotId,
            $mode,
            $now
        ): array {
            $slot = Db::table('ch_expert_slot')
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $slotId)
                ->where('expert_id', $expertId)
                ->lock(true)
                ->find();
            if (!is_array($slot)) {
                throw new MemberTransactionException(404, 'slot_not_found', 'Expert slot was not found');
            }
            if ((string) $slot['status'] !== 'open') {
                throw new MemberTransactionException(409, 'slot_unavailable', 'Expert slot is no longer available');
            }

            $pointsCost = $mode === 'online' ? self::ONLINE_POINTS : self::OFFLINE_POINTS;
            $cashCost = $mode === 'online' ? self::ONLINE_CASH : self::OFFLINE_CASH;

            $appointmentId = (int) Db::table('ch_appointment')->insertGetId([
                'tenant_id' => $tenantId,
                'expert_id' => $expertId,
                'member_id' => (int) $member['id'],
                'slot_id' => (int) $slot['id'],
                'mode' => $mode,
                'status' => 'pending',
                'points_cost' => $pointsCost,
                'cash_cost' => $cashCost,
                'created_at' => $now,
            ]);
            if ($appointmentId <= 0) {
                throw new MemberTransactionException(409, 'appointment_failed', 'Appointment could not be created');
            }

            Db::table('ch_expert_slot')
                ->where('id', (int) $slot['id'])
                ->where('tenant_id', $tenantId)
                ->where('status', 'open')
                ->update(['status' => 'booked']);

            return [
                'id' => $appointmentId,
                'expert_id' => $expertId,
                'member_id' => (int) $member['id'],
                'slot_id' => (int) $slot['id'],
                'mode' => $mode,
                'status' => 'pending',
                'points_cost' => $pointsCost,
                'cash_cost' => $cashCost,
                'created_at' => $now,
            ];
        });

        return $this->success($appointment);
    }

    private function findExpert(int $tenantId, int $expertId): ?array
    {
        $mentorRoles = Db::table('ch_persona_role')
            ->where('tenant_id', $tenantId)
            ->whereIn('code', ['mentor', 'coach', 'industry_leader'])
            ->where('status', 1)
            ->where('is_del', 0)
            ->column('id');

        $expert = null;
        if ($mentorRoles) {
            $member = Db::table('ch_tenant_member')
                ->where('tenant_id', $tenantId)
                ->where('id', $expertId)
                ->where('primary_role_id', 'in', $mentorRoles)
                ->where('status', 1)
                ->where('is_del', 0)
                ->find();
            if (is_array($member)) {
                $profile = Db::table('ch_member_profile')
                    ->where('tenant_id', $tenantId)
                    ->where('member_id', $expertId)
                    ->find();
                if (is_array($profile) && trim((string) $profile['real_name']) !== '') {
                    $expert = [
                        'id' => (int) $member['id'],
                        'name' => (string) $profile['real_name'],
                        'title' => (string) ($profile['job_title'] ?? ''),
                        'company' => (string) ($profile['company_name'] ?? ''),
                        'bio' => (string) ($profile['bio'] ?? ''),
                        'industry' => (string) ($profile['industry'] ?? ''),
                    ];
                }
            }
        }

        if ($expert === null) {
            $expert = $this->seedExpert($expertId);
        }

        return $expert;
    }

    private function seedExpert(int $expertId): ?array
    {
        $seeds = [
            1 => ['name' => '陈明远', 'title' => '知名导师 · 行业领袖', 'company' => '明德恒智咨询', 'bio' => '深耕企业家教练领域 15 年，服务过 200+ 家企业创始人。', 'industry' => '企业服务'],
            2 => ['name' => '李一舟', 'title' => 'AI 增长教练', 'company' => '智舟咨询', 'bio' => '专注 AI 与组织效能，帮助 100+ 团队完成 AI 转型落地。', 'industry' => '人工智能'],
            3 => ['name' => '王慧', 'title' => '公益慈善家', 'company' => '向善公益', 'bio' => '长期投身商业向善事业，发起多个企业家公益项目。', 'industry' => '公益慈善'],
        ];
        if (!isset($seeds[$expertId])) {
            return null;
        }
        $seed = $seeds[$expertId];

        return [
            'id' => $expertId,
            'name' => $seed['name'],
            'title' => $seed['title'],
            'company' => $seed['company'],
            'bio' => $seed['bio'],
            'industry' => $seed['industry'],
        ];
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
        throw new MemberTransactionException(422, 'request_validation_failed', 'id must be a positive integer');
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
