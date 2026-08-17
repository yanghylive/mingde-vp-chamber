<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventListQuery;
use app\chamber\activity\EventRegistrationListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** User-facing, tenant-scoped activity catalogue and qualification projection. */
final class EventService
{
    private const PUBLIC_LIST_STATUSES = [
        EventEligibility::EVENT_PUBLISHED,
        EventEligibility::EVENT_REGISTRATION_CLOSED,
    ];

    private const PUBLIC_DETAIL_STATUSES = [
        EventEligibility::EVENT_PUBLISHED,
        EventEligibility::EVENT_REGISTRATION_CLOSED,
        EventEligibility::EVENT_ENDED,
        EventEligibility::EVENT_CANCELLED,
    ];

    private const REGISTRATION_STATUSES = [
        0 => 'pending_payment',
        1 => 'registered',
        2 => 'cancelled',
        3 => 'refunded',
        4 => 'waitlisted',
        5 => 'completed',
    ];

    /** @var callable */
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: function (): int {
            return time();
        };
    }

    public function list(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        EventListQuery $filters
    ): array {
        $now = $this->now();
        $member = $this->member($tenant, $auth);
        $facts = $this->qualificationFacts($tenant, $member, $now);

        $query = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('is_del', 0)
            ->whereIn('status', self::PUBLIC_LIST_STATUSES)
            ->where('end_time', '>', $now);

        if ($filters->eventType() !== null) {
            $query->where('event_type', $filters->eventType());
        }
        if ($filters->databaseStatus() !== null) {
            $query->where('status', $filters->databaseStatus());
        }
        if ($filters->tag() !== '') {
            $encodedTag = json_encode($filters->tag(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $query->whereRaw(
                "JSON_CONTAINS(COALESCE(NULLIF(tags_json, ''), '[]'), ?)",
                [$encodedTag]
            );
        }
        if ($filters->query() !== '') {
            $like = '%' . $filters->query() . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('title', 'like', $like)
                    ->whereOr('summary', 'like', $like)
                    ->whereOr('location_name', 'like', $like)
                    ->whereOr('address', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('start_time', 'asc')
            ->order('id', 'asc')
            ->page($filters->page(), $filters->limit())
            ->select()
            ->toArray();

        $eventIds = array_map(function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $tickets = $this->ticketRowsByEvent($tenant->tenantId(), $eventIds);
        $registrations = $this->registrationStatuses(
            $tenant->tenantId(),
            $auth->uid(),
            $eventIds
        );
        $items = [];
        foreach ($rows as $row) {
            $eventId = (int) $row['id'];
            $items[] = $this->formatEvent(
                $row,
                $tickets[$eventId] ?? [],
                $member,
                $facts,
                $now,
                $registrations[$eventId] ?? null
            );
        }

        $totalPages = $total === 0 ? 0 : (int) ceil($total / $filters->limit());

        return [
            'items' => $items,
            'page' => [
                'page' => $filters->page(),
                'limit' => $filters->limit(),
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page() < $totalPages,
            ],
        ];
    }

    public function detail(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $eventId
    ): array {
        if ($eventId <= 0) {
            throw $this->validation('event_id', 'invalid_value', 'event_id must be a positive integer');
        }
        $now = $this->now();
        $member = $this->member($tenant, $auth);
        $row = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $eventId)
            ->where('is_del', 0)
            ->whereIn('status', self::PUBLIC_DETAIL_STATUSES)
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'event_not_found', 'Event was not found');
        }

        $facts = $this->qualificationFacts($tenant, $member, $now);
        $registration = $this->registrationStatuses($tenant->tenantId(), $auth->uid(), [$eventId]);

        return $this->formatEvent(
            $row,
            $this->ticketRowsByEvent($tenant->tenantId(), [$eventId])[$eventId] ?? [],
            $member,
            $facts,
            $now,
            $registration[$eventId] ?? null
        );
    }

    public function listRegistrations(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        EventRegistrationListQuery $filters
    ): array {
        $member = $this->member($tenant, $auth);
        $query = $this->registrationQuery($tenant, $member)
            ->field('registration.*');
        if ($filters->databaseStatus() !== null) {
            $query->where('registration.status', $filters->databaseStatus());
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('registration.add_time', 'desc')
            ->order('registration.id', 'desc')
            ->page($filters->page(), $filters->limit())
            ->select()
            ->toArray();
        $items = $this->formatRegistrations($tenant->tenantId(), $rows);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $filters->limit());

        return [
            'items' => $items,
            'page' => [
                'page' => $filters->page(),
                'limit' => $filters->limit(),
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page() < $totalPages,
            ],
        ];
    }

    public function registrationDetail(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $registrationId
    ): array {
        if ($registrationId <= 0) {
            throw $this->validation(
                'registration_id',
                'invalid_value',
                'registration_id must be a positive integer'
            );
        }
        $member = $this->member($tenant, $auth);
        $row = $this->registrationQuery($tenant, $member)
            ->where('registration.id', $registrationId)
            ->field('registration.*')
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(
                404,
                'registration_not_found',
                'Event registration was not found'
            );
        }

        return $this->formatRegistrations($tenant->tenantId(), [$row])[0];
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        // 匿名游客：无会员身份，返回空数组（下游 qualificationFacts/formatEvent 已兼容空 member）
        if ($auth->isAnonymous()) {
            return [];
        }
        $row = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(
                403,
                'tenant_scope_denied',
                'Member is not active in the requested channel'
            );
        }

        return $row;
    }

    private function qualificationFacts(TenantContext $tenant, array $member, int $now): array
    {
        // 匿名游客：无角色、无积分
        if (!isset($member['id'])) {
            return ['role_codes' => [], 'points' => 0];
        }
        $roles = Db::table('ch_member_role')->alias('member_role')
            ->join(
                ['ch_persona_role' => 'persona_role'],
                'persona_role.id = member_role.role_id AND persona_role.tenant_id = member_role.tenant_id'
            )
            ->where('member_role.tenant_id', $tenant->tenantId())
            ->where('member_role.member_id', (int) $member['id'])
            ->where('member_role.status', 1)
            ->where('member_role.effective_time', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->where('member_role.expire_time', 0)
                    ->whereOr('member_role.expire_time', '>', $now);
            })
            ->where('persona_role.status', 1)
            ->where('persona_role.is_del', 0)
            ->column('persona_role.code');

        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', (int) $member['id'])
            ->find();

        return [
            'role_codes' => array_values(array_unique(array_map('strval', $roles))),
            'points' => is_array($account) ? max(0, (int) $account['balance']) : 0,
        ];
    }

    private function ticketRowsByEvent(int $tenantId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = Db::table('ch_event_ticket')
            ->where('tenant_id', $tenantId)
            ->whereIn('event_id', $eventIds)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['event_id']][] = $row;
        }

        return $grouped;
    }

    private function registrationStatuses(int $tenantId, int $uid, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = Db::table('ch_event_registration')
            ->where('tenant_id', $tenantId)
            ->where('uid', $uid)
            ->whereIn('event_id', $eventIds)
            ->field('event_id,status')
            ->select()
            ->toArray();
        $statuses = [];
        foreach ($rows as $row) {
            $status = (int) $row['status'];
            if (isset(self::REGISTRATION_STATUSES[$status])) {
                $statuses[(int) $row['event_id']] = self::REGISTRATION_STATUSES[$status];
            }
        }

        return $statuses;
    }

    private function registrationQuery(TenantContext $tenant, array $member)
    {
        return Db::table('ch_event_registration')->alias('registration')
            ->join(
                ['ch_event' => 'event'],
                'event.id = registration.event_id AND event.tenant_id = registration.tenant_id'
            )
            ->where('registration.tenant_id', $tenant->tenantId())
            ->where('registration.member_id', (int) $member['id'])
            ->where('registration.uid', (int) $member['uid'])
            ->where('event.channel_id', $tenant->channelId())
            ->where('event.is_del', 0);
    }

    private function formatRegistrations(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $contextIds = [];
        $registrationIds = [];
        foreach ($rows as $row) {
            $registrationIds[] = (int) $row['id'];
            if ((int) ($row['order_context_id'] ?? 0) > 0) {
                $contextIds[] = (int) $row['order_context_id'];
            }
        }
        $contexts = [];
        if ($contextIds !== []) {
            $contextRows = Db::table('ch_order_context')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_values(array_unique($contextIds)))
                ->select()
                ->toArray();
            foreach ($contextRows as $context) {
                $contexts[(int) $context['id']] = $context;
            }
        }
        $checked = [];
        $checkinRows = Db::table('ch_event_checkin')
            ->where('tenant_id', $tenantId)
            ->whereIn('registration_id', $registrationIds)
            ->field('registration_id')
            ->select()
            ->toArray();
        foreach ($checkinRows as $checkin) {
            $checked[(int) $checkin['registration_id']] = true;
        }
        $refundAttempts = $this->refundAttemptsByRegistrationId($tenantId, $registrationIds);

        $items = [];
        foreach ($rows as $row) {
            $status = (int) $row['status'];
            if (!isset(self::REGISTRATION_STATUSES[$status])) {
                throw new MemberTransactionException(
                    500,
                    'event_serialization_failed',
                    'Event registration status is invalid'
                );
            }
            $context = $contexts[(int) ($row['order_context_id'] ?? 0)] ?? null;
            $paidAt = (int) $row['paid_time'];
            $item = [
                'id' => (int) $row['id'],
                'registration_no' => (string) $row['registration_no'],
                'event_id' => (int) $row['event_id'],
                'ticket_id' => (int) $row['ticket_id'],
                'status' => self::REGISTRATION_STATUSES[$status],
                'amount' => number_format((float) $row['amount'], 2, '.', ''),
                'integral_amount' => (int) $row['integral_amount'],
                'order_no' => (string) ($row['order_no'] ?? ''),
                'order_status' => is_array($context) ? $this->orderStatus($context, $status) : null,
                'refund' => $this->refundSummary($refundAttempts[(int) $row['id']] ?? null, $context),
                'payment_required' => is_array($context)
                    && (int) $context['pay_status'] === 0
                    && (float) $context['payable_amount'] > 0,
                'checked_in' => isset($checked[(int) $row['id']]),
                'created_at' => (int) $row['add_time'],
            ];
            if ($paidAt > 0) {
                $item['paid_at'] = $paidAt;
            }
            $items[] = $item;
        }

        return $items;
    }

    private function refundAttemptsByRegistrationId(int $tenantId, array $registrationIds): array
    {
        if ($registrationIds === []) {
            return [];
        }
        $sourceIds = array_map('strval', array_values(array_unique(array_map('intval', $registrationIds))));
        $rows = Db::table('ch_refund_attempt')
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'event_registration')
            ->whereIn('source_id', $sourceIds)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $attempts = [];
        foreach ($rows as $row) {
            $attempts[(int) $row['source_id']] = $row;
        }

        return $attempts;
    }

    private function refundSummary(?array $attempt, ?array $context): ?array
    {
        if (!is_array($attempt)) {
            return null;
        }
        $cumulative = is_array($context)
            ? (string) ($context['refunded_amount'] ?? '0.00')
            : (string) ($attempt['cumulative_after'] ?? '0.00');

        return [
            'refund_no' => (string) $attempt['refund_no'],
            'provider' => (string) $attempt['provider'],
            'provider_refund_no' => (string) $attempt['provider_refund_no'],
            'status' => (string) $attempt['status'],
            'amount' => number_format((float) $attempt['amount'], 2, '.', ''),
            'cumulative_refunded_amount' => number_format((float) $cumulative, 2, '.', ''),
            'provider_status' => (string) $attempt['provider_status'],
            'final_confirmed' => (int) $attempt['final_confirmed'] === 1,
            'final_confirm_source' => (string) $attempt['final_confirm_source'],
            'failure_code' => (string) $attempt['failure_code'],
            'query_retry_count' => (int) $attempt['query_retry_count'],
            'next_query_time' => (int) $attempt['next_query_time'],
            'updated_at' => (int) $attempt['update_time'],
        ];
    }

    private function orderStatus(array $context, int $registrationStatus): string
    {
        $refundStatus = (int) $context['refund_status'];
        if ($refundStatus === 4) {
            return 'refunded';
        }
        if (in_array($refundStatus, [1, 2], true)) {
            return 'refund_pending';
        }
        if ($refundStatus === 3) {
            return 'partially_refunded';
        }
        if (in_array((int) $context['pay_status'], [2, 3], true)) {
            return 'cancelled';
        }
        if ((int) $context['pay_status'] === 0) {
            return 'pending_payment';
        }
        if ($registrationStatus === 5) {
            return 'completed';
        }

        return 'paid';
    }

    private function formatEvent(
        array $row,
        array $ticketRows,
        array $member,
        array $facts,
        int $now,
        ?string $registrationStatus
    ): array {
        $tickets = [];
        foreach ($ticketRows as $ticket) {
            $remaining = (int) $ticket['capacity'] > 0
                ? max(0, (int) $ticket['capacity'] - (int) $ticket['reserved_count'] - (int) $ticket['paid_count'])
                : null;
            $reason = EventEligibility::reason(
                $row,
                $ticket,
                $member,
                $facts['role_codes'],
                $facts['points'],
                $now,
                $remaining === null || $remaining > 0
            );
            $tickets[] = [
                'id' => (int) $ticket['id'],
                'name' => (string) $ticket['name'],
                'price' => number_format((float) $ticket['price'], 2, '.', ''),
                'integral_price' => (int) $ticket['integral_price'],
                'capacity' => (int) $ticket['capacity'],
                'reserved_count' => (int) $ticket['reserved_count'],
                'paid_count' => (int) $ticket['paid_count'],
                'remaining' => $remaining,
                'min_tier' => (int) $ticket['min_tier'],
                'eligibility' => EventEligibility::normalizeRules($ticket['eligibility_json'] ?? []),
                'refund_policy' => $this->refundPolicy($ticket['refund_policy_json'] ?? null),
                'sale_start_time' => (int) $ticket['sale_start_time'],
                'sale_end_time' => (int) $ticket['sale_end_time'],
                'status' => (int) $ticket['status'],
                'sort' => (int) $ticket['sort'],
                'eligible' => $reason === null,
                'ineligible_reason' => $reason,
            ];
        }

        return [
            'id' => (int) $row['id'],
            'event_no' => (string) $row['event_no'],
            'event_type' => (string) $row['event_type'],
            'title' => (string) $row['title'],
            'cover_image' => (string) $row['cover_image'],
            'summary' => (string) $row['summary'],
            'detail' => (string) ($row['detail'] ?? ''),
            'tags' => $this->decodeList($row['tags_json'] ?? null),
            'speakers' => $this->decodeList($row['speakers_json'] ?? null),
            'start_time' => (int) $row['start_time'],
            'end_time' => (int) $row['end_time'],
            'signup_start_time' => (int) $row['signup_start_time'],
            'signup_end_time' => (int) $row['signup_end_time'],
            'location_name' => (string) $row['location_name'],
            'address' => (string) $row['address'],
            'longitude' => number_format((float) $row['longitude'], 6, '.', ''),
            'latitude' => number_format((float) $row['latitude'], 6, '.', ''),
            'min_tier' => (int) $row['min_tier'],
            'eligibility' => EventEligibility::normalizeRules($row['eligibility_json'] ?? []),
            'refund_policy' => $this->refundPolicy($row['refund_policy_json'] ?? null),
            'checkin_reward_points' => (int) ($row['checkin_reward_points'] ?? 0),
            'checkin_reward_contribution' => (int) ($row['checkin_reward_contribution'] ?? 0),
            'status' => (int) $row['status'],
            'publish_time' => (int) $row['publish_time'],
            'registered' => in_array($registrationStatus, ['pending_payment', 'registered', 'waitlisted', 'completed'], true),
            'registration_status' => $registrationStatus,
            'tickets' => $tickets,
        ];
    }

    private function decodeList($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) && array_keys($decoded) === array_values(array_keys($decoded)) ? $decoded : [];
    }

    private function refundPolicy($value): array
    {
        $decoded = [];
        if (is_string($value) && trim($value) !== '') {
            $candidate = json_decode($value, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        } elseif (is_array($value)) {
            $decoded = $value;
        }

        return [
            'mode' => in_array($decoded['mode'] ?? null, ['none', 'full_before_deadline', 'partial_before_deadline'], true)
                ? $decoded['mode'] : 'none',
            'deadline_time' => max(0, (int) ($decoded['deadline_time'] ?? 0)),
            'percent' => max(0, min(100, (int) ($decoded['percent'] ?? 100))),
            'description' => (string) ($decoded['description'] ?? ''),
        ];
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function validation(string $field, string $code, string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'request_validation_failed',
            $message,
            [['field' => $field, 'code' => $code]]
        );
    }
}
