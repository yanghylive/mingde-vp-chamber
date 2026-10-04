<?php

declare(strict_types=1);

use app\chamber\commerce\EventRefundGatewayResult;
use app\chamber\commerce\RefundAttemptState;
use app\chamber\contracts\EventRefundGatewayInterface;
use think\facade\Db;

/** Fake channel gateway for refund admin tests (no external calls). */
class StubRefundGateway implements EventRefundGatewayInterface
{
    /** @var string */
    private $queryStatus;

    public function __construct(string $queryStatus)
    {
        $this->queryStatus = $queryStatus;
    }

    public function loadOrder(int $orderPk): array
    {
        return ['id' => max(1, $orderPk), 'trade_no' => 'STUB' . max(1, $orderPk)];
    }

    public function provider(array $order): string
    {
        return 'balance';
    }

    public function supportsAutomaticAmount(array $order, string $amount, string $remaining): bool
    {
        return true;
    }

    public function submitApplication(
        array $order,
        string $providerRefundNo,
        string $amount,
        string $reason
    ): EventRefundGatewayResult {
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::PROCESSING,
            'provider_status' => 'provider_accepted',
            'provider_refund_no' => $providerRefundNo,
            'provider_refund_id' => '',
            'crmeb_refund_id' => 0,
            'response_hash' => hash('sha256', 'stub-submit:' . $providerRefundNo),
            'failure_code' => '',
            'final_source' => '',
        ]);
    }

    public function query(array $attempt): EventRefundGatewayResult
    {
        if ($this->queryStatus === RefundAttemptState::FAILED) {
            return EventRefundGatewayResult::fromArray([
                'status' => RefundAttemptState::FAILED,
                'provider_status' => 'application_rejected',
                'provider_refund_no' => (string) ($attempt['provider_refund_no'] ?? ''),
                'provider_refund_id' => '',
                'crmeb_refund_id' => 0,
                'response_hash' => hash('sha256', 'stub-query-failed'),
                'failure_code' => 'refund_application_rejected',
                'final_source' => '',
            ]);
        }

        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::PROCESSING,
            'provider_status' => 'application_pending',
            'provider_refund_no' => (string) ($attempt['provider_refund_no'] ?? ''),
            'provider_refund_id' => '',
            'crmeb_refund_id' => 0,
            'response_hash' => hash('sha256', 'stub-query-pending'),
            'failure_code' => '',
            'final_source' => '',
        ]);
    }
}

class RacyRefundGateway extends StubRefundGateway
{
    /** @var int */
    private $attemptId;

    public function __construct(int $attemptId)
    {
        parent::__construct(RefundAttemptState::SUCCEEDED);
        $this->attemptId = $attemptId;
    }

    public function query(array $attempt): EventRefundGatewayResult
    {
        // 模拟并发的人工确认先落库：渠道查询返回成功时尝试已是 MANUAL。
        Db::table('ch_refund_attempt')->where('id', $this->attemptId)->update([
            'status' => RefundAttemptState::MANUAL,
            'final_confirmed' => 1,
            'final_confirm_source' => RefundAttemptState::SOURCE_MANUAL,
            'update_time' => time(),
        ]);

        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::SUCCEEDED,
            'provider_status' => 'provider_confirmed',
            'provider_refund_no' => (string) ($attempt['provider_refund_no'] ?? ''),
            'provider_refund_id' => 'PROVIDER-RACE-1',
            'crmeb_refund_id' => 0,
            'response_hash' => hash('sha256', 'stub-query-race'),
            'failure_code' => '',
            'final_source' => RefundAttemptState::SOURCE_PROVIDER_QUERY,
        ]);
    }
}
