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

    /** 定价表化（P0）：读 ch_expert_pricing，无记录回退默认值 */
    private function pricing(int $tenantId, int $expertId): array
    {
        $row = Db::table('ch_expert_pricing')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $expertId)
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

            $pricing = $this->pricing($tenantId, $expertId);
            $pointsCost = $mode === 'online' ? (int) $pricing['online_points'] : (int) $pricing['offline_points'];
            $cashCost = $mode === 'online' ? (string) $pricing['online_cash'] : (string) $pricing['offline_cash'];

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
