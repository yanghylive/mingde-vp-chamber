<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventCheckinToken;
use app\chamber\activity\EventEligibility;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\tenancy\TenantContext;
use app\chamber\commerce\Money;
use think\facade\Db;
use Throwable;

/** Owns the operator-side event and ticket lifecycle. */
final class EventAdminService
{
    private const EVENT_TYPES = ['growth', 'industry', 'public_welfare'];
    private const MANAGE_PERMISSION = 'chamber.event.manage';
    private const CHECKIN_PERMISSION = 'chamber.event.checkin';

    public function create(TenantContext $tenant, AuthenticatedAdminContext $admin, array $input): array
    {
        $admin->assertPermission(self::MANAGE_PERMISSION);
        $event = $this->normalizeEvent($input, false);
        $tickets = $this->normalizeTickets($input['tickets'] ?? []);
        $now = time();

        $eventId = (int) Db::transaction(function () use ($tenant, $admin, $event, $tickets, $now): int {
            $eventNo = $this->eventNo($tenant->tenantId(), $now);
            $id = (int) Db::table('ch_event')->insertGetId([
                'tenant_id' => $tenant->tenantId(),
                'channel_id' => $tenant->channelId(),
                'event_no' => $eventNo,
                'event_type' => $event['event_type'],
                'title' => $event['title'],
                'cover_image' => $event['cover_image'],
                'summary' => $event['summary'],
                'detail' => $event['detail'],
                'tags_json' => $this->json($event['tags']),
                'speakers_json' => $this->json($event['speakers']),
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'signup_start_time' => $event['signup_start_time'],
                'signup_end_time' => $event['signup_end_time'],
                'location_name' => $event['location_name'],
                'address' => $event['address'],
                'longitude' => $event['longitude'],
                'latitude' => $event['latitude'],
                'min_tier' => $event['min_tier'],
                'eligibility_json' => $this->json($event['eligibility']),
                'refund_policy_json' => $this->json($event['refund_policy']),
                'checkin_reward_points' => $event['checkin_reward_points'],
                'checkin_reward_contribution' => $event['checkin_reward_contribution'],
                'status' => EventEligibility::EVENT_DRAFT,
                'created_admin_id' => $admin->adminId(),
                'publish_time' => 0,
                'add_time' => $now,
                'update_time' => $now,
                'is_del' => 0,
            ]);
            if ($id <= 0) {
                throw $this->failure('event_create_failed', 'Event could not be created');
            }
            $this->replaceTickets($tenant->tenantId(), $id, $tickets, $now);

            return $id;
        });

        return $this->detail($tenant, $eventId, true);
    }

    public function update(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $eventId,
        array $input
    ): array {
        $admin->assertPermission(self::MANAGE_PERMISSION);
        if ($eventId <= 0) {
            throw $this->validation('event_id must be a positive integer');
        }
        $event = $this->normalizeEvent($input, true);
        $tickets = array_key_exists('tickets', $input) ? $this->normalizeTickets($input['tickets']) : null;
        $now = time();

        Db::transaction(function () use ($tenant, $admin, $eventId, $event, $tickets, $now): void {
            $row = $this->lockEvent($tenant, $eventId);
            if ((int) $row['status'] !== EventEligibility::EVENT_DRAFT) {
                throw $this->conflict('event_edit_locked', 'Only draft events can be edited');
            }
            $updates = [
                'update_time' => $now,
            ];
            foreach ($event as $key => $value) {
                if ($key === 'tags' || $key === 'speakers' || $key === 'eligibility' || $key === 'refund_policy') {
                    $column = $key . '_json';
                    $updates[$column] = $this->json($value);
                } else {
                    $updates[$key] = $value;
                }
            }
            $changed = Db::table('ch_event')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', $eventId)
                ->update($updates);
            if ($changed !== 1) {
                throw $this->failure('event_update_failed', 'Event could not be updated');
            }
            if ($tickets !== null) {
                $this->replaceTickets($tenant->tenantId(), $eventId, $tickets, $now);
            }
        });

        return $this->detail($tenant, $eventId, true);
    }

    public function publish(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $eventId
    ): array {
        $admin->assertPermission(self::MANAGE_PERMISSION);
        $now = time();
        Db::transaction(function () use ($tenant, $admin, $eventId, $now): void {
            $event = $this->lockEvent($tenant, $eventId);
            if ((int) $event['status'] !== EventEligibility::EVENT_DRAFT) {
                throw $this->conflict('event_publish_locked', 'Only draft events can be published');
            }
            $tickets = $this->ticketRows($tenant->tenantId(), $eventId, true);
            $this->assertPublishable($event, $tickets, $now);
            $updated = Db::table('ch_event')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', $eventId)
                ->where('status', EventEligibility::EVENT_DRAFT)
                ->update([
                    'status' => EventEligibility::EVENT_PUBLISHED,
                    'publish_time' => $now,
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw $this->failure('event_publish_failed', 'Event could not be published');
            }
        });

        return $this->detail($tenant, $eventId, false);
    }

    public function cancel(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $eventId,
        string $reason = ''
    ): array {
        $admin->assertPermission(self::MANAGE_PERMISSION);
        $reason = trim($reason);
        if (strlen($reason) > 500) {
            throw $this->validation('cancel reason is too long');
        }
        $now = time();
        Db::transaction(function () use ($tenant, $admin, $eventId, $reason, $now): void {
            $event = $this->lockEvent($tenant, $eventId);
            if ((int) $event['status'] === EventEligibility::EVENT_CANCELLED) {
                return;
            }
            if ((int) $event['status'] === EventEligibility::EVENT_ENDED) {
                throw $this->conflict('event_cancel_locked', 'Ended events cannot be cancelled');
            }
            $active = (int) Db::table('ch_event_registration')
                ->where('tenant_id', $tenant->tenantId())
                ->where('event_id', $eventId)
                ->whereIn('status', [0, 1, 4, 5])
                ->count();
            if ($active > 0) {
                throw $this->conflict('event_cancel_has_registrations', 'Events with active registrations need a refund workflow');
            }
            Db::table('ch_event')->where('tenant_id', $tenant->tenantId())->where('id', $eventId)->update([
                'status' => EventEligibility::EVENT_CANCELLED,
                'update_time' => $now,
            ]);
            Db::table('ch_audit_record')->insert([
                'tenant_id' => $tenant->tenantId(),
                'business_type' => 'event',
                'business_id' => $eventId,
                'action' => 'cancel',
                'from_status' => (int) $event['status'],
                'to_status' => EventEligibility::EVENT_CANCELLED,
                'operator_type' => 2,
                'operator_id' => $admin->adminId(),
                'opinion' => $reason,
                'extra_json' => '{}',
                'add_time' => $now,
            ]);
        });

        return $this->detail($tenant, $eventId, false);
    }

    public function detail(TenantContext $tenant, int $eventId, bool $includeDraft = true): array
    {
        $query = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $eventId)
            ->where('is_del', 0);
        if (!$includeDraft) {
            $query->where('status', '<>', EventEligibility::EVENT_DRAFT);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'event_not_found', 'Event was not found');
        }

        return $this->formatEvent($row, $this->ticketRows($tenant->tenantId(), $eventId, false));
    }

    public function issueCheckinToken(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $eventId,
        int $ttl = 300
    ): array {
        $admin->assertPermission(self::CHECKIN_PERMISSION);
        $event = $this->lockEvent($tenant, $eventId);
        if ((int) $event['status'] !== EventEligibility::EVENT_PUBLISHED) {
            throw $this->conflict('event_not_open', 'Only published events can issue check-in tokens');
        }
        $now = time();
        try {
            $issued = EventCheckinToken::issue($tenant->tenantId(), $eventId, $now, $ttl);
        } catch (Throwable $exception) {
            throw new MemberTransactionException(503, 'checkin_token_unavailable', 'Check-in token signing is not configured');
        }
        $id = (int) Db::table('ch_event_checkin_token')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'event_id' => $eventId,
            'token_digest' => $issued['digest'],
            'issued_by_admin_id' => $admin->adminId(),
            'valid_from' => $issued['valid_from'],
            'expires_time' => $issued['expires_time'],
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw $this->failure('checkin_token_unavailable', 'Check-in token could not be stored');
        }

        return [
            'token_id' => $id,
            'token' => $issued['token'],
            'valid_from' => $issued['valid_from'],
            'expires_time' => $issued['expires_time'],
        ];
    }

    public function manualCheckin(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $eventId,
        int $registrationId,
        string $reason
    ): array {
        $admin->assertPermission(self::CHECKIN_PERMISSION);
        if ($eventId <= 0) {
            throw $this->validation('event_id must be a positive integer');
        }
        if ($registrationId <= 0) {
            throw $this->validation('registration_id must be a positive integer');
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw $this->validation('reason must contain 1 to 500 characters');
        }
        $now = time();

        return Db::transaction(function () use ($tenant, $admin, $eventId, $registrationId, $reason, $now): array {
            $event = $this->lockEvent($tenant, $eventId);
            if (in_array((int) $event['status'], [
                EventEligibility::EVENT_DRAFT,
                EventEligibility::EVENT_CANCELLED,
            ], true)) {
                throw $this->conflict('event_not_open', 'Only active events can be checked in');
            }

            $registration = Db::table('ch_event_registration')
                ->where('tenant_id', $tenant->tenantId())
                ->where('event_id', $eventId)
                ->where('id', $registrationId)
                ->lock(true)
                ->find();
            if (!is_array($registration)
                || (int) $registration['member_id'] <= 0
                || (int) $registration['uid'] <= 0
                || !in_array((int) $registration['status'], [1, 5], true)) {
                throw new MemberTransactionException(404, 'registration_not_found', 'Event registration was not found');
            }

            $existing = Db::table('ch_event_checkin')
                ->where('tenant_id', $tenant->tenantId())
                ->where('registration_id', $registrationId)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                throw $this->conflict('checkin_already_completed', 'Event registration is already checked in');
            }

            try {
                $checkinId = (int) Db::table('ch_event_checkin')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'member_id' => (int) $registration['member_id'],
                    'uid' => (int) $registration['uid'],
                    'checkin_type' => 2,
                    'token_digest' => '',
                    'operator_admin_id' => $admin->adminId(),
                    'reason' => $reason,
                    'checked_time' => $now,
                    'add_time' => $now,
                ]);
            } catch (Throwable $exception) {
                $existing = Db::table('ch_event_checkin')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('registration_id', $registrationId)
                    ->find();
                if (is_array($existing)) {
                    throw $this->conflict('checkin_already_completed', 'Event registration is already checked in');
                }
                throw $this->failure('event_reward_failed', 'Event check-in could not be recorded');
            }
            if ($checkinId <= 0) {
                throw $this->failure('event_reward_failed', 'Event check-in could not be recorded');
            }

            return [
                'id' => $checkinId,
                'event_id' => $eventId,
                'registration_id' => $registrationId,
                'checked_at' => $now,
                'checkin_type' => 'manual',
                'replayed' => false,
            ];
        });
    }

    public function lockEvent(TenantContext $tenant, int $eventId): array
    {
        if ($eventId <= 0) {
            throw $this->validation('event_id must be a positive integer');
        }
        $row = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $eventId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'event_not_found', 'Event was not found');
        }

        return $row;
    }

    /** @return array<int, array> */
    public function ticketRows(int $tenantId, int $eventId, bool $lock = false): array
    {
        $query = Db::table('ch_event_ticket')
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc');
        if ($lock) {
            $query->lock(true);
        }

        return $query->select()->toArray();
    }

    private function replaceTickets(int $tenantId, int $eventId, array $tickets, int $now): void
    {
        Db::table('ch_event_ticket')->where('tenant_id', $tenantId)->where('event_id', $eventId)->update([
            'is_del' => 1,
            'status' => 0,
            'update_time' => $now,
        ]);
        foreach ($tickets as $ticket) {
            $id = (int) Db::table('ch_event_ticket')->insertGetId([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'name' => $ticket['name'],
                'price' => $ticket['price'],
                'integral_price' => $ticket['integral_price'],
                'product_id' => $ticket['product_id'],
                'product_attr_unique' => $ticket['product_attr_unique'],
                'capacity' => $ticket['capacity'],
                'reserved_count' => 0,
                'paid_count' => 0,
                'min_tier' => $ticket['min_tier'],
                'eligibility_json' => $this->json($ticket['eligibility']),
                'refund_policy_json' => $this->json($ticket['refund_policy']),
                'sale_start_time' => $ticket['sale_start_time'],
                'sale_end_time' => $ticket['sale_end_time'],
                'status' => $ticket['status'],
                'sort' => $ticket['sort'],
                'add_time' => $now,
                'update_time' => $now,
                'is_del' => 0,
            ]);
            if ($id <= 0) {
                throw $this->failure('event_ticket_create_failed', 'Event ticket could not be created');
            }
        }
    }

    private function assertPublishable(array $event, array $tickets, int $now): void
    {
        if (trim((string) $event['title']) === '') {
            throw $this->validation('Event title is required', [['field' => 'title', 'code' => 'required']]);
        }
        if ((int) $event['start_time'] <= $now || (int) $event['end_time'] <= (int) $event['start_time']) {
            throw $this->validation('Event time window is invalid', [['field' => 'start_time', 'code' => 'invalid_window']]);
        }
        if ((int) $event['signup_start_time'] <= 0
            || (int) $event['signup_end_time'] <= (int) $event['signup_start_time']
            || (int) $event['signup_end_time'] > (int) $event['start_time']) {
            throw $this->validation('Event signup window must end before the event starts', [['field' => 'signup_end_time', 'code' => 'invalid_window']]);
        }
        if ($tickets === []) {
            throw $this->validation('At least one active ticket is required', [['field' => 'tickets', 'code' => 'required']]);
        }
        foreach ($tickets as $ticket) {
            if ((int) $ticket['status'] !== 1) {
                continue;
            }
            if (Money::toMinor((string) $ticket['price']) > 0 && (int) $ticket['product_id'] <= 0) {
                throw $this->validation('Cash tickets must map to a CRMEB product', [['field' => 'tickets.product_id', 'code' => 'required']]);
            }
            if ((int) $ticket['price'] === 0 && (int) $ticket['integral_price'] === 0 && (int) $ticket['product_id'] > 0) {
                // A zero-price native product is allowed for the CRMEB zero-yuan path.
                continue;
            }
        }
    }

    private function normalizeEvent(array $input, bool $partial): array
    {
        $allowed = [
            'event_type', 'title', 'cover_image', 'summary', 'detail', 'tags', 'speakers',
            'start_time', 'end_time', 'signup_start_time', 'signup_end_time', 'location_name',
            'address', 'longitude', 'latitude', 'min_tier', 'eligibility', 'refund_policy',
            'checkin_reward_points', 'checkin_reward_contribution',
        ];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $this->validation('Unknown event field: ' . $key);
            }
        }
        $defaults = [
            'event_type' => 'growth', 'title' => '', 'cover_image' => '', 'summary' => '', 'detail' => '',
            'tags' => [], 'speakers' => [], 'start_time' => 0, 'end_time' => 0,
            'signup_start_time' => 0, 'signup_end_time' => 0, 'location_name' => '', 'address' => '',
            'longitude' => '0.000000', 'latitude' => '0.000000', 'min_tier' => 1,
            'eligibility' => [], 'refund_policy' => [],
            'checkin_reward_points' => 0, 'checkin_reward_contribution' => 0,
        ];
        $result = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                if ($partial) {
                    continue;
                }
                $value = $defaults[$key];
            } else {
                $value = $input[$key];
            }
            switch ($key) {
                case 'event_type':
                    if (!is_string($value) || !in_array($value, self::EVENT_TYPES, true)) {
                        throw $this->validation('event_type is invalid', [['field' => $key, 'code' => 'invalid_value']]);
                    }
                    break;
                case 'title':
                    $value = $this->text($value, 160, $key, !$partial || array_key_exists($key, $input));
                    break;
                case 'cover_image':
                    $value = $this->text($value, 255, $key, false);
                    break;
                case 'summary':
                    $value = $this->text($value, 500, $key, false);
                    break;
                case 'detail':
                    $value = $this->text($value, 200000, $key, false);
                    break;
                case 'location_name':
                case 'address':
                    $value = $this->text($value, $key === 'address' ? 255 : 120, $key, false);
                    break;
                case 'start_time':
                case 'end_time':
                case 'signup_start_time':
                case 'signup_end_time':
                case 'checkin_reward_points':
                case 'checkin_reward_contribution':
                    $value = $this->nonNegativeInt($value, $key);
                    break;
                case 'min_tier':
                    $value = $this->nonNegativeInt($value, $key);
                    if ($value < 1 || $value > 4) {
                        throw $this->validation($key . ' must be between 1 and 4');
                    }
                    break;
                case 'longitude':
                case 'latitude':
                    $value = $this->coordinate($value, $key);
                    break;
                case 'tags':
                    $value = $this->strings($value, 20, 40, $key);
                    break;
                case 'speakers':
                    $value = $this->speakers($value);
                    break;
                case 'eligibility':
                    $value = EventEligibility::normalizeRules($value);
                    break;
                case 'refund_policy':
                    $value = $this->refundPolicy($value);
                    break;
            }
            $result[$key] = $value;
        }
        if (!$partial && ((int) $result['end_time'] > 0 && (int) $result['start_time'] >= (int) $result['end_time'])) {
            throw $this->validation('end_time must be after start_time');
        }

        return $result;
    }

    private function normalizeTickets($value): array
    {
        if (!is_array($value) || $value === [] || array_keys($value) !== range(0, count($value) - 1)) {
            throw $this->validation('tickets must be a non-empty list');
        }
        if (count($value) > 50) {
            throw $this->validation('tickets may contain at most 50 items');
        }
        $result = [];
        foreach ($value as $index => $ticket) {
            if (!is_array($ticket)) {
                throw $this->validation('ticket must be an object', [['field' => 'tickets[' . $index . ']', 'code' => 'invalid_value']]);
            }
            $allowed = ['name', 'price', 'integral_price', 'product_id', 'product_attr_unique', 'capacity', 'min_tier', 'eligibility', 'refund_policy', 'sale_start_time', 'sale_end_time', 'status', 'sort'];
            foreach (array_keys($ticket) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw $this->validation('Unknown ticket field: ' . $key);
                }
            }
            $name = $this->text($ticket['name'] ?? '', 80, 'tickets.name', true);
            $price = Money::assertAmount($ticket['price'] ?? '0.00', 'ticket.price');
            $integral = $this->nonNegativeInt($ticket['integral_price'] ?? 0, 'ticket.integral_price');
            $productId = $this->nonNegativeInt($ticket['product_id'] ?? 0, 'ticket.product_id');
            $capacity = $this->nonNegativeInt($ticket['capacity'] ?? 0, 'ticket.capacity');
            $minTier = $this->nonNegativeInt($ticket['min_tier'] ?? 1, 'ticket.min_tier');
            if ($minTier < 1 || $minTier > 4) {
                throw $this->validation('ticket.min_tier must be between 1 and 4');
            }
            $result[] = [
                'name' => $name,
                'price' => $price,
                'integral_price' => $integral,
                'product_id' => $productId,
                'product_attr_unique' => $this->text($ticket['product_attr_unique'] ?? '', 20, 'ticket.product_attr_unique', false),
                'capacity' => $capacity,
                'min_tier' => $minTier,
                'eligibility' => EventEligibility::normalizeRules($ticket['eligibility'] ?? []),
                'refund_policy' => $this->refundPolicy($ticket['refund_policy'] ?? []),
                'sale_start_time' => $this->nonNegativeInt($ticket['sale_start_time'] ?? 0, 'ticket.sale_start_time'),
                'sale_end_time' => $this->nonNegativeInt($ticket['sale_end_time'] ?? 0, 'ticket.sale_end_time'),
                'status' => ((int) ($ticket['status'] ?? 1) === 0 ? 0 : 1),
                'sort' => $this->nonNegativeInt($ticket['sort'] ?? 0, 'ticket.sort'),
            ];
        }

        return $result;
    }

    private function formatEvent(array $row, array $tickets): array
    {
        return [
            'id' => (int) $row['id'],
            'event_no' => (string) $row['event_no'],
            'event_type' => (string) $row['event_type'],
            'title' => (string) $row['title'],
            'cover_image' => (string) $row['cover_image'],
            'summary' => (string) $row['summary'],
            'detail' => (string) ($row['detail'] ?? ''),
            'tags' => $this->decode($row['tags_json'] ?? '[]'),
            'speakers' => $this->decode($row['speakers_json'] ?? '[]'),
            'start_time' => (int) $row['start_time'],
            'end_time' => (int) $row['end_time'],
            'signup_start_time' => (int) $row['signup_start_time'],
            'signup_end_time' => (int) $row['signup_end_time'],
            'location' => [
                'name' => (string) $row['location_name'],
                'address' => (string) $row['address'],
                'longitude' => (string) $row['longitude'],
                'latitude' => (string) $row['latitude'],
            ],
            'min_tier' => (int) $row['min_tier'],
            'eligibility' => EventEligibility::normalizeRules($row['eligibility_json'] ?? []),
            'refund_policy' => $this->refundPolicy($this->decode($row['refund_policy_json'] ?? '{}')),
            'checkin_reward_points' => (int) ($row['checkin_reward_points'] ?? 0),
            'checkin_reward_contribution' => (int) ($row['checkin_reward_contribution'] ?? 0),
            'status' => (int) $row['status'],
            'publish_time' => (int) $row['publish_time'],
            'tickets' => array_map(function (array $ticket): array {
                return [
                    'id' => (int) $ticket['id'],
                    'name' => (string) $ticket['name'],
                    'price' => (string) $ticket['price'],
                    'integral_price' => (int) $ticket['integral_price'],
                    'capacity' => (int) $ticket['capacity'],
                    'reserved_count' => (int) $ticket['reserved_count'],
                    'paid_count' => (int) $ticket['paid_count'],
                    'remaining' => (int) $ticket['capacity'] > 0
                        ? max(0, (int) $ticket['capacity'] - (int) $ticket['reserved_count'] - (int) $ticket['paid_count'])
                        : null,
                    'min_tier' => (int) $ticket['min_tier'],
                    'eligibility' => EventEligibility::normalizeRules($ticket['eligibility_json'] ?? []),
                    'refund_policy' => $this->refundPolicy($this->decode($ticket['refund_policy_json'] ?? '{}')),
                    'sale_start_time' => (int) $ticket['sale_start_time'],
                    'sale_end_time' => (int) $ticket['sale_end_time'],
                    'status' => (int) $ticket['status'],
                    'sort' => (int) $ticket['sort'],
                ];
            }, $tickets),
        ];
    }

    private function text($value, int $max, string $field, bool $required): string
    {
        if (!is_string($value)) {
            throw $this->validation($field . ' must be a string');
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw $this->validation($field . ' is required');
        }
        if (strlen($value) > $max) {
            throw $this->validation($field . ' is too long');
        }

        return $value;
    }

    private function strings($value, int $maxItems, int $maxLength, string $field): array
    {
        if (!is_array($value) || array_keys($value) !== array_values(array_keys($value)) || count($value) > $maxItems) {
            throw $this->validation($field . ' must be a list');
        }
        $result = [];
        foreach ($value as $item) {
            $text = $this->text($item, $maxLength, $field, true);
            $result[$text] = true;
        }

        return array_keys($result);
    }

    private function speakers($value): array
    {
        if (!is_array($value) || array_keys($value) !== array_values(array_keys($value)) || count($value) > 50) {
            throw $this->validation('speakers must be a list');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw $this->validation('speaker must be an object');
            }
            $allowed = ['name', 'title', 'organization', 'avatar'];
            foreach (array_keys($item) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw $this->validation('Unknown speaker field: ' . $key);
                }
            }
            $result[] = [
                'name' => $this->text($item['name'] ?? '', 80, 'speaker.name', true),
                'title' => $this->text($item['title'] ?? '', 80, 'speaker.title', false),
                'organization' => $this->text($item['organization'] ?? '', 120, 'speaker.organization', false),
                'avatar' => $this->text($item['avatar'] ?? '', 255, 'speaker.avatar', false),
            ];
        }

        return $result;
    }

    private function refundPolicy($value): array
    {
        if ($value === null || $value === '') {
            $value = [];
        }
        if (!is_array($value)) {
            throw $this->validation('refund_policy must be an object');
        }
        $allowed = ['mode', 'deadline_time', 'percent', 'description'];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $this->validation('Unknown refund_policy field: ' . $key);
            }
        }
        $mode = $value['mode'] ?? 'none';
        if (!is_string($mode) || !in_array($mode, ['none', 'full_before_deadline', 'partial_before_deadline'], true)) {
            throw $this->validation('refund_policy.mode is invalid');
        }
        $percent = $this->nonNegativeInt($value['percent'] ?? 100, 'refund_policy.percent');
        if ($percent > 100) {
            throw $this->validation('refund_policy.percent must be at most 100');
        }

        return [
            'mode' => $mode,
            'deadline_time' => $this->nonNegativeInt($value['deadline_time'] ?? 0, 'refund_policy.deadline_time'),
            'percent' => $percent,
            'description' => $this->text($value['description'] ?? '', 500, 'refund_policy.description', false),
        ];
    }

    private function coordinate($value, string $field): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw $this->validation($field . ' is invalid');
        }
        if (!is_numeric((string) $value)) {
            throw $this->validation($field . ' is invalid');
        }
        $number = (float) $value;
        if (($field === 'longitude' && ($number < -180 || $number > 180))
            || ($field === 'latitude' && ($number < -90 || $number > 90))) {
            throw $this->validation($field . ' is out of range');
        }

        return number_format($number, 6, '.', '');
    }

    private function nonNegativeInt($value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw $this->validation($field . ' must be a non-negative integer');
        }

        return $value;
    }

    private function eventNo(int $tenantId, int $now): string
    {
        return strtoupper(substr(hash('sha256', $tenantId . ':event:' . $now . ':' . bin2hex(random_bytes(8))), 0, 32));
    }

    private function json($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw $this->failure('event_serialization_failed', 'Event JSON snapshot could not be encoded');
        }

        return $encoded;
    }

    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function validation(string $message, array $fields = []): MemberTransactionException
    {
        return new MemberTransactionException(422, 'request_validation_failed', $message, $fields);
    }

    private function conflict(string $reason, string $message): MemberTransactionException
    {
        return new MemberTransactionException(409, $reason, $message);
    }

    private function failure(string $reason, string $message): MemberTransactionException
    {
        return new MemberTransactionException(503, $reason, $message);
    }
}
