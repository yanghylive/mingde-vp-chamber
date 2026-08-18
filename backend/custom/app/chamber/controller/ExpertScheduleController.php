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
        $expert = Db::table('ch_expert')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $expertId)
            ->find();
        if (!is_array($expert)) {
            throw new MemberTransactionException(404, 'expert_not_found', 'Expert was not found');
        }

        $role = (string) ($expert['role'] ?? 'mentor');
        if ($role === '') {
            $role = 'mentor';
        }

        return $this->success([
            'id'          => (int) $expert['id'],
            'name'        => (string) $expert['name'],
            'title'       => (string) $expert['title'],
            'company'     => (string) $expert['company'],
            'industry'    => (string) $expert['industry'],
            'bio'         => (string) $expert['bio'],
            'role'        => $role,
            'profile'     => $this->decodeJsonMap((string) ($expert['profile_json'] ?? '')),
            'role_fields' => $this->roleFields($tenant->tenantId(), $role),
            'cases'       => $this->cases($tenant->tenantId(), $expertId),
            'credentials' => $this->credentials($tenant->tenantId(), $expertId),
            'courses'     => $this->courses($tenant->tenantId(), $expertId),
            'pricing'     => $this->pricing($tenant->tenantId(), $expertId),
        ]);
    }

    /** 定价：统一读 ch_expert 表（与 admin 录入、列表展示一致；ch_expert_pricing 表已废弃不再读） */
    private function pricing(int $tenantId, int $expertId): array
    {
        $row = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expertId)
            ->find();

        if (is_array($row)) {
            return [
                'online_points' => (int) ($row['online_points'] ?? 0),
                'online_cash' => (string) ($row['online_cash'] ?? '0.00'),
                'offline_points' => (int) ($row['offline_points'] ?? 0),
                'offline_cash' => (string) ($row['offline_cash'] ?? '0.00'),
            ];
        }

        return [
            'online_points' => self::ONLINE_POINTS,
            'online_cash' => self::ONLINE_CASH,
            'offline_points' => self::OFFLINE_POINTS,
            'offline_cash' => self::OFFLINE_CASH,
        ];
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
            ->where('deleted_at', 0)
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

        // 客户端幂等键：同一 Idempotency-Key 重试返回第一次创建的预约，不重复扣积分。
        // 必填：此前缺 key 时随机生成 'gen:' 前缀键，唯一约束失去防重复意义（每次随机=每次新预约）
        $idemKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idemKey === '' || strlen($idemKey) > 128) {
            throw new MemberTransactionException(
                422,
                'idempotency_key_required',
                'Idempotency-Key header is required (same key on retry returns the same appointment)'
            );
        }
        $bookingKey = hash('sha256', $idemKey);

        $appointment = Db::transaction(function () use (
            $tenantId,
            $expertId,
            $member,
            $slotId,
            $mode,
            $now,
            $bookingKey
        ): array {
            $slot = Db::table('ch_expert_slot')
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $slotId)
                ->where('expert_id', $expertId)
                ->where('deleted_at', 0)
                ->lock(true)
                ->find();
            if (!is_array($slot)) {
                throw new MemberTransactionException(404, 'slot_not_found', 'Expert slot was not found');
            }

            // 幂等重放：同一 booking_key 已有预约，直接返回第一次结果（不重复扣积分/占档期）
            $existing = Db::table('ch_appointment')
                ->where('tenant_id', $tenantId)
                ->where('member_id', (int) $member['id'])
                ->where('booking_key', $bookingKey)
                ->find();
            if (is_array($existing)) {
                return [
                    'id' => (int) $existing['id'],
                    'expert_id' => (int) $existing['expert_id'],
                    'member_id' => (int) $existing['member_id'],
                    'slot_id' => (int) $existing['slot_id'],
                    'mode' => (string) $existing['mode'],
                    'status' => (string) $existing['status'],
                    'points_cost' => (int) $existing['points_cost'],
                    'cash_cost' => (string) $existing['cash_cost'],
                    'created_at' => (int) $existing['created_at'],
                    'replayed' => true,
                ];
            }

            if ((string) $slot['status'] !== 'open') {
                throw new MemberTransactionException(409, 'slot_unavailable', 'Expert slot is no longer available');
            }
            if ((int) $slot['start_time'] <= $now) {
                throw new MemberTransactionException(409, 'slot_expired', '该档期已过期');
            }
            if ((int) $slot['end_time'] <= (int) $slot['start_time']) {
                throw new MemberTransactionException(409, 'slot_invalid', '档期时间非法');
            }
            // mode(online/offline) 必须与档期 location(0线上/1线下) 一致
            $expectLocation = $mode === 'online' ? 0 : 1;
            if ((int) $slot['location'] !== $expectLocation) {
                throw new MemberTransactionException(409, 'mode_location_mismatch', '预约方式与档期类型不匹配');
            }

            $pricing = $this->pricing($tenantId, $expertId);
            $pointsCost = $mode === 'online' ? (int) $pricing['online_points'] : (int) $pricing['offline_points'];
            if ($pointsCost <= 0) {
                throw new MemberTransactionException(409, 'pricing_unavailable', '大咖定价未配置，请稍后再试');
            }
            // 现金暂不收（微信支付商户号未配置），cash_cost 记 0，商户号开通后补收
            $cashCost = '0.00';

            // 扣积分（乐观锁 + 账本）
            $account = $this->identity->pointsAccount($tenantId, $member, true);
            $balance = (int) $account['balance'];
            if ($balance < $pointsCost) {
                throw new MemberTransactionException(409, 'insufficient_points', '积分不足，需要 ' . $pointsCost . ' 积分');
            }
            $newBalance = $balance - $pointsCost;
            $updated = Db::table('ch_point_account')
                ->where('id', (int) $account['id'])
                ->where('tenant_id', $tenantId)
                ->where('version', (int) $account['version'])
                ->update([
                    'balance' => $newBalance,
                    'version' => (int) $account['version'] + 1,
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw new MemberTransactionException(409, 'points_conflict', '积分账户已变动，请重试');
            }

            $appointmentId = (int) Db::table('ch_appointment')->insertGetId([
                'tenant_id' => $tenantId,
                'expert_id' => $expertId,
                'member_id' => (int) $member['id'],
                'uid' => (int) $member['uid'],
                'slot_id' => (int) $slot['id'],
                'mode' => $mode,
                'status' => 'confirmed',
                'points_cost' => $pointsCost,
                'cash_cost' => $cashCost,
                'booking_key' => $bookingKey,
                'slot_start_time' => (int) $slot['start_time'],
                'slot_end_time' => (int) $slot['end_time'],
                'location' => (int) $slot['location'],
                'created_at' => $now,
                'add_time' => $now,
            ]);
            if ($appointmentId <= 0) {
                throw new MemberTransactionException(409, 'appointment_failed', 'Appointment could not be created');
            }

            Db::table('ch_point_ledger')->insert([
                'tenant_id' => $tenantId,
                'account_id' => (int) $account['id'],
                'member_id' => (int) $member['id'],
                'uid' => (int) $member['uid'],
                'delta' => -1 * $pointsCost,
                'balance_after' => $newBalance,
                'source_type' => 'appointment',
                'source_id' => (string) $appointmentId,
                'remark' => '预约大咖（' . ($mode === 'online' ? '线上' : '线下') . '）',
                'idempotency_key' => hash('sha256', 'appointment_points:' . $tenantId . ':' . $appointmentId),
                'status' => 1,
                'reversal_id' => 0,
                'add_time' => $now,
            ]);

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
                'status' => 'confirmed',
                'points_cost' => $pointsCost,
                'cash_cost' => $cashCost,
                'created_at' => $now,
            ];
        });

        return $this->success($appointment);
    }

    /** 取消预约：退积分 + 档期回 open */
    public function cancelAppointment(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $appointment_id
    ): Response {
        $appointmentId = $this->positiveId($appointment_id);
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $now = time();

        $result = Db::transaction(function () use ($tenantId, $member, $appointmentId, $now): array {
            $appointment = Db::table('ch_appointment')
                ->where('tenant_id', $tenantId)
                ->where('id', $appointmentId)
                ->where('member_id', (int) $member['id'])
                ->lock(true)
                ->find();
            if (!is_array($appointment)) {
                throw new MemberTransactionException(404, 'appointment_not_found', '预约不存在');
            }
            if ((string) $appointment['status'] !== 'confirmed') {
                throw new MemberTransactionException(409, 'appointment_not_cancellable', '当前预约状态不允许取消');
            }

            $pointsCost = (int) $appointment['points_cost'];

            $account = $this->identity->pointsAccount($tenantId, $member, true);
            $balance = (int) $account['balance'];
            $newBalance = $balance + $pointsCost;
            $updated = Db::table('ch_point_account')
                ->where('id', (int) $account['id'])
                ->where('tenant_id', $tenantId)
                ->where('version', (int) $account['version'])
                ->update([
                    'balance' => $newBalance,
                    'version' => (int) $account['version'] + 1,
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw new MemberTransactionException(409, 'points_conflict', '积分账户已变动，请重试');
            }

            Db::table('ch_point_ledger')->insert([
                'tenant_id' => $tenantId,
                'account_id' => (int) $account['id'],
                'member_id' => (int) $member['id'],
                'uid' => (int) $member['uid'],
                'delta' => $pointsCost,
                'balance_after' => $newBalance,
                'source_type' => 'appointment_cancel',
                'source_id' => (string) $appointmentId,
                'remark' => '取消预约退积分',
                'idempotency_key' => hash('sha256', 'appointment_cancel_points:' . $tenantId . ':' . $appointmentId),
                'status' => 1,
                'reversal_id' => 0,
                'add_time' => $now,
            ]);

            Db::table('ch_appointment')
                ->where('id', $appointmentId)
                ->update(['status' => 'cancelled', 'cancel_time' => $now]);

            Db::table('ch_expert_slot')
                ->where('id', (int) $appointment['slot_id'])
                ->where('tenant_id', $tenantId)
                ->update(['status' => 'open']);

            return [
                'id' => $appointmentId,
                'status' => 'cancelled',
                'points_refunded' => $pointsCost,
            ];
        });

        return $this->success($result);
    }

    /** 我的预约列表 */
    public function myAppointments(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Db::table('ch_appointment')
            ->where('tenant_id', $tenantId)
            ->where('member_id', (int) $member['id'])
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        // 批量查 expert 消除 N+1；时段信息用快照字段，不依赖时段表（时段被删也能展示历史）
        $expertIds = array_values(array_unique(array_filter(array_map(static function ($r) {
            return (int) $r['expert_id'];
        }, $rows))));

        $expertMap = [];
        if ($expertIds) {
            foreach (Db::table('ch_expert')->whereIn('id', $expertIds)->select()->toArray() as $e) {
                $expertMap[(int) $e['id']] = $e;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $expert = $expertMap[(int) $row['expert_id']] ?? null;
            $items[] = [
                'id' => (int) $row['id'],
                'expert_id' => (int) $row['expert_id'],
                'expert_name' => is_array($expert) ? (string) $expert['name'] : '',
                'slot_id' => (int) $row['slot_id'],
                'start_time' => (int) $row['slot_start_time'],
                'end_time' => (int) $row['slot_end_time'],
                'mode' => (string) $row['mode'],
                'status' => (string) $row['status'],
                'points_cost' => (int) $row['points_cost'],
                'cash_cost' => (string) $row['cash_cost'],
                'created_at' => (int) $row['created_at'],
                'cancel_time' => (int) ($row['cancel_time'] ?? 0),
            ];
        }

        return $this->success(['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    private function decodeJsonMap(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function roleFields(int $tenantId, string $role): array
    {
        $rows = Db::table('ch_expert_role_field')
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'field_key'   => (string) $r['field_key'],
                'field_label' => (string) $r['field_label'],
                'field_type'  => (string) $r['field_type'],
                'placeholder' => (string) $r['placeholder'],
            ];
        }

        return $items;
    }

    private function cases(int $tenantId, int $expertId): array
    {
        $rows = Db::table('ch_expert_case')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $expertId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'          => (int) $r['id'],
                'title'       => (string) $r['title'],
                'description' => (string) $r['description'],
                'industry'    => (string) $r['industry'],
                'year'        => (int) $r['year'],
            ];
        }

        return $items;
    }

    private function credentials(int $tenantId, int $expertId): array
    {
        $rows = Db::table('ch_expert_credential')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $expertId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'     => (int) $r['id'],
                'name'   => (string) $r['name'],
                'issuer' => (string) $r['issuer'],
                'year'   => (int) $r['year'],
            ];
        }

        return $items;
    }

    private function courses(int $tenantId, int $expertId): array
    {
        $rows = Db::table('ch_expert_course')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $expertId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'      => (int) $r['id'],
                'title'   => (string) $r['title'],
                'summary' => (string) $r['summary'],
            ];
        }

        return $items;
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
