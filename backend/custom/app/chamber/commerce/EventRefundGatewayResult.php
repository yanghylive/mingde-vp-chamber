<?php

declare(strict_types=1);

namespace app\chamber\commerce;

use InvalidArgumentException;

final class EventRefundGatewayResult
{
    /** @var array */
    private $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromArray(array $input): self
    {
        $allowed = [
            'status', 'provider_status', 'provider_refund_no', 'provider_refund_id',
            'crmeb_refund_id', 'response_hash', 'failure_code', 'final_source',
        ];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unknown refund gateway result field');
            }
        }
        $status = self::identifier($input['status'] ?? null, 16, 'status', false);
        RefundAttemptState::assertStatus($status);
        if (!in_array($status, [
            RefundAttemptState::PROCESSING,
            RefundAttemptState::UNKNOWN,
            RefundAttemptState::SUCCEEDED,
            RefundAttemptState::FAILED,
        ], true)) {
            throw new InvalidArgumentException('Gateway cannot return requested or manual status');
        }
        $source = self::identifier($input['final_source'] ?? '', 32, 'final_source', true);
        if ($status === RefundAttemptState::SUCCEEDED) {
            RefundAttemptState::assertFinalConfirmation($status, $source);
        } elseif ($source !== '') {
            throw new InvalidArgumentException('Non-final gateway result cannot contain final_source');
        }

        return new self([
            'status' => $status,
            'provider_status' => self::identifier($input['provider_status'] ?? '', 32, 'provider_status', true),
            'provider_refund_no' => self::identifier($input['provider_refund_no'] ?? '', 96, 'provider_refund_no', true),
            'provider_refund_id' => self::identifier($input['provider_refund_id'] ?? '', 128, 'provider_refund_id', true),
            'crmeb_refund_id' => self::nonNegativeInteger($input['crmeb_refund_id'] ?? 0),
            'response_hash' => self::hash($input['response_hash'] ?? ''),
            'failure_code' => self::identifier($input['failure_code'] ?? '', 64, 'failure_code', true),
            'final_source' => $source,
        ]);
    }

    public function status(): string { return $this->values['status']; }
    public function providerStatus(): string { return $this->values['provider_status']; }
    public function providerRefundNo(): string { return $this->values['provider_refund_no']; }
    public function providerRefundId(): string { return $this->values['provider_refund_id']; }
    public function crmebRefundId(): int { return $this->values['crmeb_refund_id']; }
    public function responseHash(): string { return $this->values['response_hash']; }
    public function failureCode(): string { return $this->values['failure_code']; }
    public function finalSource(): string { return $this->values['final_source']; }

    private static function identifier($value, int $max, string $field, bool $empty): string
    {
        if (!is_string($value) || (!$empty && $value === '') || strlen($value) > $max
            || ($value !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException($field . ' is invalid');
        }
        return $value;
    }

    private static function nonNegativeInteger($value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('crmeb_refund_id is invalid');
        }
        return $value;
    }

    private static function hash($value): string
    {
        if (!is_string($value) || ($value !== '' && preg_match('/^[a-f0-9]{64}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('response_hash is invalid');
        }
        return $value;
    }
}
