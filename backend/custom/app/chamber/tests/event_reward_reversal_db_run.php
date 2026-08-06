<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\services\EventRewardReversalService;
use app\chamber\services\EventRewardService;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$tenant = Db::table('ch_tenant')->where('slug', 'local-primary')->where('status', 1)->find();
if (!is_array($tenant)) {
    throw new RuntimeException('Primary tenant fixture is unavailable');
}
$channel = Db::table('ch_channel')->where('tenant_id', (int) $tenant['id'])->where('code', 'default')->find();
if (!is_array($channel)) {
    throw new RuntimeException('Primary channel fixture is unavailable');
}

Db::startTrans();
try {
    [$memberId, $uid] = rewardMember((int) $tenant['id'], (int) $channel['id'], 50, $now);
    [$eventId, $registrationId] = rewardRegistration(
        (int) $tenant['id'],
        (int) $channel['id'],
        $memberId,
        $uid,
        'RR' . strtoupper($runId),
        $now
    );
    $grantKey = hash('sha256', 'reward-reversal-grant:' . $runId);
    $grant = (new EventRewardService())->grant(
        (int) $tenant['id'],
        $eventId,
        $registrationId,
        $uid,
        'attendance',
        25,
        7,
        $grantKey,
        $now
    );
    rewardAssertSame(false, $grant['replayed']);
    rewardAssertSame(75, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));

    $fingerprint = hash('sha256', 'trusted-refund-completion:' . $runId);
    $service = new EventRewardReversalService();
    $reversal = $service->reverseForRefund(
        (int) $tenant['id'],
        $eventId,
        $registrationId,
        $uid,
        $fingerprint,
        $now + 1
    );
    rewardAssertSame(true, $reversal['reversed']);
    rewardAssertSame(false, $reversal['replayed']);
    rewardAssertSame(25, $reversal['points']);
    rewardAssertSame(7, $reversal['contribution']);
    rewardAssertSame(50, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    rewardAssertSame(2, (int) Db::table('ch_event_reward')->where('registration_id', $registrationId)->count());

    $originalReward = Db::table('ch_event_reward')->where('id', (int) $grant['reward_id'])->find();
    $reversalReward = Db::table('ch_event_reward')
        ->where('registration_id', $registrationId)
        ->where('reward_type', 'refund_reversal')
        ->find();
    rewardAssertTrue(is_array($originalReward), 'Original reward must remain available');
    rewardAssertTrue(is_array($reversalReward), 'Reversal reward must be recorded');
    rewardAssertSame(2, (int) $originalReward['status']);
    rewardAssertSame((int) $reversalReward['id'], (int) $originalReward['reversal_id']);
    rewardAssertSame((int) $originalReward['id'], (int) $reversalReward['reversal_id']);
    rewardAssertSame(2, (int) $reversalReward['status']);

    $pointOriginal = Db::table('ch_point_ledger')
        ->where('idempotency_key', hash('sha256', $grantKey . ':points'))->find();
    $pointReversal = Db::table('ch_point_ledger')
        ->where('source_type', 'event_checkin_refund')->where('source_id', (string) $registrationId)->find();
    rewardAssertTrue(is_array($pointOriginal), 'Original point ledger must remain available');
    rewardAssertTrue(is_array($pointReversal), 'Point reversal ledger must be recorded');
    rewardAssertSame(2, (int) $pointOriginal['status']);
    rewardAssertSame((int) $pointReversal['id'], (int) $pointOriginal['reversal_id']);
    rewardAssertSame(-25, (int) $pointReversal['delta']);
    rewardAssertSame((int) $pointOriginal['id'], (int) $pointReversal['reversal_id']);
    rewardAssertSame(50, (int) $pointReversal['balance_after']);

    $contributionOriginal = Db::table('ch_contribution_ledger')
        ->where('idempotency_key', hash('sha256', $grantKey . ':contribution'))->find();
    $contributionReversal = Db::table('ch_contribution_ledger')
        ->where('source_type', 'event_checkin_refund')->where('source_id', (string) $registrationId)->find();
    rewardAssertTrue(is_array($contributionOriginal), 'Original contribution ledger must remain available');
    rewardAssertTrue(is_array($contributionReversal), 'Contribution reversal ledger must be recorded');
    rewardAssertSame(2, (int) $contributionOriginal['status']);
    rewardAssertSame((int) $contributionReversal['id'], (int) $contributionOriginal['reversal_id']);
    rewardAssertSame(-7, (int) $contributionReversal['delta']);
    rewardAssertSame((int) $contributionOriginal['id'], (int) $contributionReversal['reversal_id']);

    $replay = $service->reverseForRefund(
        (int) $tenant['id'],
        $eventId,
        $registrationId,
        $uid,
        $fingerprint,
        $now + 2
    );
    rewardAssertSame(true, $replay['replayed']);
    rewardAssertSame($reversal['reversal_ids'], $replay['reversal_ids']);
    rewardAssertSame(2, (int) Db::table('ch_event_reward')->where('registration_id', $registrationId)->count());
    rewardAssertSame(2, (int) Db::table('ch_point_ledger')->where('source_id', (string) $registrationId)->count());
    rewardAssertSame(2, (int) Db::table('ch_contribution_ledger')->where('source_id', (string) $registrationId)->count());

    rewardExpectReason('event_reward_reversal_failed', 409, function () use (
        $service,
        $tenant,
        $eventId,
        $registrationId,
        $uid,
        $runId,
        $now
    ): void {
        $service->reverseForRefund(
            (int) $tenant['id'],
            $eventId,
            $registrationId,
            $uid,
            hash('sha256', 'different-completion:' . $runId),
            $now + 3
        );
    });

    [$spentMemberId, $spentUid] = rewardMember((int) $tenant['id'], (int) $channel['id'], 0, $now);
    [$spentEventId, $spentRegistrationId] = rewardRegistration(
        (int) $tenant['id'],
        (int) $channel['id'],
        $spentMemberId,
        $spentUid,
        'RS' . strtoupper($runId),
        $now
    );
    $spentGrantKey = hash('sha256', 'reward-reversal-spent:' . $runId);
    $spentGrant = (new EventRewardService())->grant(
        (int) $tenant['id'],
        $spentEventId,
        $spentRegistrationId,
        $spentUid,
        'attendance',
        20,
        2,
        $spentGrantKey,
        $now
    );
    Db::table('ch_point_account')->where('member_id', $spentMemberId)->update([
        'balance' => 5,
        'version' => 3,
        'update_time' => $now + 1,
    ]);
    rewardExpectReason('event_reward_reversal_balance_insufficient', 409, function () use (
        $service,
        $tenant,
        $spentEventId,
        $spentRegistrationId,
        $spentUid,
        $runId,
        $now
    ): void {
        $service->reverseForRefund(
            (int) $tenant['id'],
            $spentEventId,
            $spentRegistrationId,
            $spentUid,
            hash('sha256', 'spent-completion:' . $runId),
            $now + 2
        );
    });
    rewardAssertSame(5, (int) Db::table('ch_point_account')->where('member_id', $spentMemberId)->value('balance'));
    rewardAssertSame(1, (int) Db::table('ch_event_reward')->where('id', (int) $spentGrant['reward_id'])->value('status'));
    rewardAssertSame(1, (int) Db::table('ch_event_reward')->where('registration_id', $spentRegistrationId)->count());
    rewardAssertSame(1, (int) Db::table('ch_point_ledger')->where('source_id', (string) $spentRegistrationId)->count());
    rewardAssertSame(1, (int) Db::table('ch_contribution_ledger')->where('source_id', (string) $spentRegistrationId)->count());

    fwrite(STDOUT, sprintf("Event reward reversal database integration passed (%d assertions).\n", $assertions));
} finally {
    Db::rollback();
}

function rewardMember(int $tenantId, int $channelId, int $balance, int $now): array
{
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(100, 10000);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId,
        'uid' => $uid,
        'first_channel_id' => $channelId,
        'current_channel_id' => $channelId,
        'referrer_uid' => 0,
        'tier' => 2,
        'verification_status' => 2,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $now,
        'tier_expire_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    Db::table('ch_point_account')->insert([
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => $balance,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    return [$memberId, $uid];
}

function rewardRegistration(
    int $tenantId,
    int $channelId,
    int $memberId,
    int $uid,
    string $eventNo,
    int $now
): array {
    $eventId = (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => 'industry',
        'title' => 'Reward reversal test',
        'cover_image' => '',
        'summary' => 'Trusted full-refund reward reversal fixture',
        'tags_json' => '[]',
        'speakers_json' => '[]',
        'detail' => '',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600,
        'signup_end_time' => $now + 3600,
        'location_name' => 'Test venue',
        'address' => '',
        'longitude' => '0.000000',
        'latitude' => '0.000000',
        'min_tier' => 2,
        'eligibility_json' => '{}',
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 25,
        'checkin_reward_contribution' => 7,
        'status' => 2,
        'created_admin_id' => 1,
        'publish_time' => $now - 100,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    $ticketId = (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'name' => 'Reward reversal ticket',
        'price' => '10.00',
        'integral_price' => 0,
        'product_id' => 1,
        'product_attr_unique' => 'reward' . $eventId,
        'capacity' => 10,
        'reserved_count' => 0,
        'paid_count' => 1,
        'min_tier' => 2,
        'eligibility_json' => '{}',
        'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600,
        'sale_end_time' => $now + 3600,
        'status' => 1,
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'ticket_id' => $ticketId,
        'member_id' => $memberId,
        'uid' => $uid,
        'registration_no' => 'RG' . $eventNo,
        'order_pk' => 1,
        'order_no' => 'ORDER' . $eventNo,
        'order_context_id' => 0,
        'amount' => '10.00',
        'integral_amount' => 0,
        'status' => 5,
        'reserve_expire_time' => 0,
        'paid_time' => $now,
        'cancel_time' => 0,
        'refund_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    return [$eventId, $registrationId];
}

function rewardExpectReason(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        rewardAssertSame($reason, $exception->reason());
        rewardAssertSame($status, $exception->httpStatus());
        return;
    }
    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function rewardAssertTrue(bool $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function rewardAssertSame($expected, $actual): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
