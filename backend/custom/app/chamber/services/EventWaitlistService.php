<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/**
 * 活动候补队列。
 *
 * 语义（2026-10-05 决策）：
 * - 只有开启 `waitlist_enabled` 的票种在满员时才进候补，未开启的维持 409 event_full。
 * - 候补期间**不冻结积分、不建订单**；席位释放后按 add_time 顺序自动转正。
 * - 转正时为会员短时锁定一个席位（ticket.reserved_count+1）并给支付窗口，
 *   窗口内完成报名即转 converted；窗口过期释放席位并顺延下一位。
 * - 转正发站内通知（ch_event_notification）。
 */
final class EventWaitlistService
{
    /** 转正支付窗口（秒） */
    public const PROMOTE_WINDOW_SECONDS = 1800;

    private const STATUS_WAITING = 'waiting';
    private const STATUS_PROMOTED = 'promoted';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_CONVERTED = 'converted';

    /** @var callable */
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: function (): int {
            return time();
        };
    }

    /**
     * 加入候补。调用方需已确认票种满员。
     *
     * @return array{waitlist_id:int,status:string,position:int,already_joined:bool}
     */
    public function join(TenantContext $tenant, AuthenticatedUserContext $auth, int $ticketId): array
    {
        if ($ticketId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'ticket_id must be a positive integer',
                [['field' => 'ticket_id', 'code' => 'invalid_value']]
            );
        }

        return Db::transaction(function () use ($tenant, $auth, $ticketId): array {
            $now = call_user_func($this->clock);
            $member = $this->member($tenant, $auth, true);
            $ticket = Db::table('ch_event_ticket')->alias('ticket')
                ->join(['ch_event' => 'event'], 'event.id = ticket.event_id AND event.tenant_id = ticket.tenant_id')
                ->where('ticket.tenant_id', $tenant->tenantId())
                ->where('ticket.id', $ticketId)
                ->where('event.channel_id', $tenant->channelId())
                ->where('event.is_del', 0)
                ->where('ticket.is_del', 0)
                ->field('ticket.*,event.status AS event_status,event.start_time,event.signup_start_time,event.signup_end_time')
                ->lock(true)
                ->find();
            if (!is_array($ticket)) {
                throw new MemberTransactionException(404, 'ticket_not_found', 'Event ticket was not found');
            }
            if ((int) ($ticket['waitlist_enabled'] ?? 0) !== 1) {
                throw new MemberTransactionException(409, 'waitlist_not_enabled', 'This ticket does not offer a waitlist');
            }
            $capacity = (int) $ticket['capacity'];
            $hasCapacity = $capacity === 0
                || ((int) $ticket['reserved_count'] + (int) $ticket['paid_count']) < $capacity;
            if ($hasCapacity) {
                throw new MemberTransactionException(409, 'ticket_available', 'This ticket still has capacity; register directly');
            }

            $existing = Db::table('ch_event_waitlist')
                ->where('tenant_id', $tenant->tenantId())
                ->where('ticket_id', $ticketId)
                ->where('member_id', (int) $member['id'])
                ->lock(true)
                ->find();
            if (is_array($existing) && in_array((string) $existing['status'], [self::STATUS_WAITING, self::STATUS_PROMOTED], true)) {
                return [
                    'waitlist_id' => (int) $existing['id'],
                    'status' => (string) $existing['status'],
                    'position' => $this->position((int) $existing['id']),
                    'already_joined' => true,
                ];
            }

            if (is_array($existing)) {
                Db::table('ch_event_waitlist')->where('id', (int) $existing['id'])->update([
                    'status' => self::STATUS_WAITING,
                    'promote_expire_time' => 0,
                    'promoted_time' => 0,
                    'promote_seq' => 0,
                    'registration_id' => 0,
                    'notified_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                $waitlistId = (int) $existing['id'];
            } else {
                $waitlistId = (int) Db::table('ch_event_waitlist')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'event_id' => (int) $ticket['event_id'],
                    'ticket_id' => $ticketId,
                    'member_id' => (int) $member['id'],
                    'uid' => (int) $member['uid'],
                    'status' => self::STATUS_WAITING,
                    'promote_expire_time' => 0,
                    'promoted_time' => 0,
                    'promote_seq' => 0,
                    'registration_id' => 0,
                    'notified_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($waitlistId <= 0) {
                    throw new MemberTransactionException(503, 'event_order_inconsistent', 'Waitlist entry could not be created');
                }
            }

            return [
                'waitlist_id' => $waitlistId,
                'status' => self::STATUS_WAITING,
                'position' => $this->position($waitlistId),
                'already_joined' => false,
            ];
        });
    }

    /** 我的候补列表。 */
    public function listForMember(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $member = $this->member($tenant, $auth, false);
        $rows = Db::table('ch_event_waitlist')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', (int) $member['id'])
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_map(function (array $row): array {
            return $this->normalize($row);
        }, $rows);
    }

    /** 退出候补（仅 waiting 可退；已转正需走取消报名/退款）。 */
    public function leave(TenantContext $tenant, AuthenticatedUserContext $auth, int $waitlistId): array
    {
        if ($waitlistId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'waitlist_id must be a positive integer',
                [['field' => 'waitlist_id', 'code' => 'invalid_value']]
            );
        }

        return Db::transaction(function () use ($tenant, $auth, $waitlistId): array {
            $now = call_user_func($this->clock);
            $member = $this->member($tenant, $auth, true);
            $row = Db::table('ch_event_waitlist')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', $waitlistId)
                ->where('member_id', (int) $member['id'])
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new MemberTransactionException(404, 'waitlist_not_found', 'Waitlist entry was not found');
            }
            if ((string) $row['status'] !== self::STATUS_WAITING) {
                throw new MemberTransactionException(409, 'waitlist_state_conflict', 'This waitlist entry can no longer be cancelled');
            }
            Db::table('ch_event_waitlist')->where('id', $waitlistId)->update([
                'status' => self::STATUS_CANCELLED,
                'update_time' => $now,
            ]);
            $row['status'] = self::STATUS_CANCELLED;
            $row['update_time'] = $now;

            return $this->normalize($row);
        });
    }

    /**
     * 席位释放后按顺序转正候补，直到票种再次满员或候补耗尽。
     * 由取消/超时释放/全额退款冲正调用。
     *
     * @return array{promoted:int,expired:int}
     */
    public function promoteForTicket(int $ticketId, int $limit = 20): array
    {
        if ($ticketId <= 0) {
            return ['promoted' => 0, 'expired' => 0];
        }

        $promoted = 0;
        $expired = 0;
        for ($i = 0; $i < $limit; $i++) {
            $outcome = $this->promoteOne($ticketId);
            if ($outcome === 'promoted') {
                $promoted++;
                continue;
            }
            $expired++;
            break;
        }

        return ['promoted' => $promoted, 'expired' => $expired];
    }

    /** 转正窗口过期回收（由 repair job 定期调用）。 */
    public function expirePromotions(int $limit = 50): array
    {
        $now = call_user_func($this->clock);
        $rows = Db::table('ch_event_waitlist')
            ->where('status', self::STATUS_PROMOTED)
            ->where('promote_expire_time', '>', 0)
            ->where('promote_expire_time', '<=', $now)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $expired = 0;
        $touchedTickets = [];
        foreach ($rows as $row) {
            $ticketId = (int) $row['ticket_id'];
            $released = Db::transaction(function () use ($row, $ticketId, $now): bool {
                $locked = Db::table('ch_event_waitlist')->where('id', (int) $row['id'])->lock(true)->find();
                if (!is_array($locked) || (string) $locked['status'] !== self::STATUS_PROMOTED) {
                    return false;
                }
                $ticket = Db::table('ch_event_ticket')->where('id', $ticketId)->lock(true)->find();
                if (!is_array($ticket) || (int) $ticket['reserved_count'] < 1) {
                    throw new MemberTransactionException(503, 'event_order_inconsistent', 'Waitlist promotion hold is missing');
                }
                Db::table('ch_event_ticket')->where('id', $ticketId)
                    ->where('reserved_count', (int) $ticket['reserved_count'])
                    ->update([
                        'reserved_count' => (int) $ticket['reserved_count'] - 1,
                        'update_time' => $now,
                    ]);
                Db::table('ch_event_waitlist')->where('id', (int) $row['id'])->update([
                    'status' => self::STATUS_EXPIRED,
                    'promote_expire_time' => 0,
                    'update_time' => $now,
                ]);

                return true;
            });
            if ($released) {
                $expired++;
                $touchedTickets[$ticketId] = true;
            }
        }

        foreach (array_keys($touchedTickets) as $ticketId) {
            $this->promoteForTicket((int) $ticketId);
        }

        return ['expired' => $expired, 'tickets' => count($touchedTickets)];
    }

    /** 转正成功后由报名服务回写：候补条目转 converted。 */
    public function markConverted(int $waitlistId, int $registrationId): void
    {
        if ($waitlistId <= 0 || $registrationId <= 0) {
            return;
        }
        Db::table('ch_event_waitlist')->where('id', $waitlistId)
            ->where('status', self::STATUS_PROMOTED)
            ->update([
                'status' => self::STATUS_CONVERTED,
                'registration_id' => $registrationId,
                'promote_expire_time' => 0,
                'update_time' => call_user_func($this->clock),
            ]);
    }

    /**
     * 供报名服务判断：该会员是否有进行中的候补转正窗口。
     *
     * @return array{waitlist_id:int,ticket_id:int}|null
     */
    public function activePromotion(TenantContext $tenant, AuthenticatedUserContext $auth, int $ticketId): ?array
    {
        $member = $this->member($tenant, $auth, false);
        $row = Db::table('ch_event_waitlist')
            ->where('tenant_id', $tenant->tenantId())
            ->where('ticket_id', $ticketId)
            ->where('member_id', (int) $member['id'])
            ->where('status', self::STATUS_PROMOTED)
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return ['waitlist_id' => (int) $row['id'], 'ticket_id' => (int) $row['ticket_id']];
    }

    /** @return 'promoted'|'full'|'empty' */
    private function promoteOne(int $ticketId): string
    {
        $now = call_user_func($this->clock);

        return Db::transaction(function () use ($ticketId, $now): string {
            $ticket = Db::table('ch_event_ticket')->where('id', $ticketId)->lock(true)->find();
            if (!is_array($ticket)) {
                return 'empty';
            }
            $capacity = (int) $ticket['capacity'];
            $hasCapacity = $capacity === 0
                || ((int) $ticket['reserved_count'] + (int) $ticket['paid_count']) < $capacity;
            if (!$hasCapacity) {
                return 'full';
            }
            $next = Db::table('ch_event_waitlist')
                ->where('tenant_id', (int) $ticket['tenant_id'])
                ->where('ticket_id', $ticketId)
                ->where('status', self::STATUS_WAITING)
                ->order('add_time', 'asc')
                ->order('id', 'asc')
                ->lock(true)
                ->find();
            if (!is_array($next)) {
                return 'empty';
            }
            $changed = Db::table('ch_event_ticket')->where('id', $ticketId)
                ->where('reserved_count', (int) $ticket['reserved_count'])
                ->update([
                    'reserved_count' => (int) $ticket['reserved_count'] + 1,
                    'update_time' => $now,
                ]);
            if ($changed !== 1) {
                throw new MemberTransactionException(503, 'event_order_inconsistent', 'Waitlist seat hold changed');
            }
            $seq = (int) $next['promote_seq'] + 1;
            Db::table('ch_event_waitlist')->where('id', (int) $next['id'])->update([
                'status' => self::STATUS_PROMOTED,
                'promote_expire_time' => $now + self::PROMOTE_WINDOW_SECONDS,
                'promoted_time' => $now,
                'promote_seq' => $seq,
                'notified_time' => $now,
                'update_time' => $now,
            ]);
            $this->notifyPromotion($next, (int) $ticket['event_id'], $now);

            return 'promoted';
        });
    }

    private function notifyPromotion(array $entry, int $eventId, int $now): void
    {
        $minutes = (int) (self::PROMOTE_WINDOW_SECONDS / 60);
        Db::table('ch_event_notification')->insert([
            'tenant_id' => (int) $entry['tenant_id'],
            'member_id' => (int) $entry['member_id'],
            'event_id' => $eventId,
            'title' => '候补转正通知',
            'body' => sprintf(
                '你候补的票种已有名额，请在 %d 分钟内完成报名，超时名额将顺延给下一位。',
                $minutes
            ),
            'read' => 0,
            'created_at' => $now,
            'add_time' => $now,
        ]);
    }

    private function position(int $waitlistId): int
    {
        $row = Db::table('ch_event_waitlist')->where('id', $waitlistId)->find();
        if (!is_array($row)) {
            return 0;
        }
        $ahead = (int) Db::table('ch_event_waitlist')
            ->where('tenant_id', (int) $row['tenant_id'])
            ->where('ticket_id', (int) $row['ticket_id'])
            ->where('status', self::STATUS_WAITING)
            ->where('add_time', '<=', (int) $row['add_time'])
            ->count();

        return max(1, $ahead);
    }

    private function normalize(array $row): array
    {
        return [
            'waitlist_id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'ticket_id' => (int) $row['ticket_id'],
            'status' => (string) $row['status'],
            'promote_expire_time' => (int) $row['promote_expire_time'],
            'registration_id' => (int) $row['registration_id'],
            'add_time' => (int) $row['add_time'],
            'update_time' => (int) $row['update_time'],
        ];
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', 'Member is not active in the requested channel');
        }

        return $row;
    }
}
