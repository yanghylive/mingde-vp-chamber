<?php

namespace app\chamber\commerce;

use InvalidArgumentException;
use JsonException;

final class CommerceEvent
{
    public const SCHEMA_VERSION = 1;

    private const COMMON_FIELDS = [
        'source', 'source_event_id', 'event_type', 'schema_version', 'occurred_at',
        'tenant_id', 'channel_id', 'order_pk', 'order_no', 'uid', 'business_type',
        'context_id', 'currency', 'paid_amount', 'correlation_id', 'event_id',
        'payload_hash',
    ];

    private const ORDER_FIELDS = [
        'completion_kind', 'pay_type', 'trade_no', 'paid_at',
    ];

    private const REFUND_FIELDS = [
        'refund_pk', 'refund_no', 'provider_refund_no', 'refund_status',
        'refund_delta', 'cumulative_refunded_amount', 'completion_id',
        'completion_source', 'provider_status',
    ];

    private const PII_KEYS = [
        'real_name', 'nickname', 'phone', 'user_phone', 'mobile', 'email',
        'address', 'user_address', 'id_card', 'identity_card', 'openid', 'open_id',
    ];

    /** @var array */
    private $payload;

    /** @var string */
    private $eventId;

    /** @var string */
    private $payloadHash;

    private function __construct(array $payload, string $eventId, string $payloadHash)
    {
        $this->payload = $payload;
        $this->eventId = $eventId;
        $this->payloadHash = $payloadHash;
    }

    public static function fromArray(array $input): self
    {
        self::assertNoPii($input);
        self::assertKnownFields($input);

        $eventType = self::requiredString($input, 'event_type', 48);
        CommerceEventType::assertSupported($eventType);
        $isRefund = CommerceEventType::isRefund($eventType);
        self::assertCompatibleFields($input, $isRefund);

        $schemaVersion = isset($input['schema_version']) ? $input['schema_version'] : self::SCHEMA_VERSION;
        if (!is_int($schemaVersion) || $schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('schema_version must be integer 1');
        }

        $payload = [
            'source' => self::identifier($input, 'source', 32),
            'source_event_id' => self::identifier($input, 'source_event_id', 128),
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'occurred_at' => self::positiveInteger($input, 'occurred_at'),
            'tenant_id' => self::positiveInteger($input, 'tenant_id'),
            'channel_id' => self::positiveInteger($input, 'channel_id'),
            'order_pk' => self::positiveInteger($input, 'order_pk'),
            'order_no' => self::identifier($input, 'order_no', 64),
            'uid' => self::positiveInteger($input, 'uid'),
            'business_type' => self::identifier($input, 'business_type', 32),
            'context_id' => self::positiveInteger($input, 'context_id'),
            'currency' => self::requiredString($input, 'currency', 3),
            'paid_amount' => Money::assertAmount(isset($input['paid_amount']) ? $input['paid_amount'] : null, 'paid_amount'),
            'correlation_id' => self::identifier($input, 'correlation_id', 128),
        ];

        if ($payload['currency'] !== 'CNY') {
            throw new InvalidArgumentException('currency must be CNY');
        }
        if (strlen($payload['correlation_id']) < 8) {
            throw new InvalidArgumentException('correlation_id must contain at least 8 characters');
        }

        if ($isRefund) {
            $payload += self::refundPayload($input, $eventType, $payload['paid_amount']);
        } else {
            $payload += self::orderPayload($input, $payload['paid_amount']);
        }

        $canonicalPayload = self::canonicalJson($payload);
        $payloadHash = hash('sha256', $canonicalPayload);
        $eventId = hash('sha256', implode("\n", [
            (string) $payload['tenant_id'],
            $payload['source'],
            $payload['event_type'],
            $payload['source_event_id'],
            (string) $payload['schema_version'],
        ]));

        if (isset($input['event_id']) && (!is_string($input['event_id']) || !hash_equals($eventId, $input['event_id']))) {
            throw new InvalidArgumentException('event_id does not match the deterministic event identity');
        }
        if (isset($input['payload_hash']) && (!is_string($input['payload_hash']) || !hash_equals($payloadHash, $input['payload_hash']))) {
            throw new InvalidArgumentException('payload_hash does not match the canonical payload');
        }

        return new self($payload, $eventId, $payloadHash);
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function payloadHash(): string
    {
        return $this->payloadHash;
    }

    public function eventType(): string
    {
        return $this->payload['event_type'];
    }

    public function source(): string
    {
        return $this->payload['source'];
    }

    public function sourceEventId(): string
    {
        return $this->payload['source_event_id'];
    }

    public function tenantId(): int
    {
        return $this->payload['tenant_id'];
    }

    public function channelId(): int
    {
        return $this->payload['channel_id'];
    }

    public function orderPk(): int
    {
        return $this->payload['order_pk'];
    }

    public function refundPk(): int
    {
        if (!CommerceEventType::isRefund($this->eventType())) {
            throw new InvalidArgumentException('Commerce event has no refund_pk');
        }

        return $this->payload['refund_pk'];
    }

    public function paidAmount(): string
    {
        return $this->payload['paid_amount'];
    }

    public function refundDelta(): string
    {
        return isset($this->payload['refund_delta']) ? $this->payload['refund_delta'] : '0.00';
    }

    public function cumulativeRefundedAmount(): string
    {
        return isset($this->payload['cumulative_refunded_amount'])
            ? $this->payload['cumulative_refunded_amount']
            : '0.00';
    }

    public function completionId(): string
    {
        return isset($this->payload['completion_id']) ? $this->payload['completion_id'] : '';
    }

    public function completionFingerprint(): string
    {
        if ($this->eventType() !== CommerceEventType::REFUND_COMPLETED) {
            throw new InvalidArgumentException('Only completed refunds have a completion fingerprint');
        }

        return hash('sha256', implode("\n", [
            (string) $this->payload['tenant_id'],
            (string) $this->payload['channel_id'],
            (string) $this->payload['order_pk'],
            (string) $this->payload['refund_pk'],
            $this->payload['refund_no'],
            (string) $this->payload['uid'],
            $this->payload['business_type'],
            (string) $this->payload['context_id'],
            $this->payload['currency'],
            $this->payload['paid_amount'],
            $this->payload['refund_delta'],
            $this->payload['cumulative_refunded_amount'],
            $this->payload['completion_id'],
        ]));
    }

    public function refundStatus(): string
    {
        return CommerceEventType::refundStatus($this->eventType());
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return ['event_id' => $this->eventId, 'payload_hash' => $this->payloadHash] + $this->payload;
    }

    public function toJson(): string
    {
        return self::canonicalJson($this->toArray());
    }

    private static function orderPayload(array $input, string $paidAmount): array
    {
        $completionKind = self::requiredString($input, 'completion_kind', 16);
        if (!in_array($completionKind, ['paid', 'zero_amount'], true)) {
            throw new InvalidArgumentException('completion_kind must be paid or zero_amount');
        }
        if ($completionKind === 'zero_amount' && $paidAmount !== '0.00') {
            throw new InvalidArgumentException('zero_amount completion requires paid_amount 0.00');
        }
        if ($completionKind === 'paid' && Money::toMinor($paidAmount) === 0) {
            throw new InvalidArgumentException('paid completion requires a positive paid_amount');
        }

        return [
            'completion_kind' => $completionKind,
            'pay_type' => self::identifier($input, 'pay_type', 24),
            'trade_no' => self::optionalIdentifier($input, 'trade_no', 96),
            'paid_at' => self::positiveInteger($input, 'paid_at'),
        ];
    }

    private static function refundPayload(array $input, string $eventType, string $paidAmount): array
    {
        if (Money::toMinor($paidAmount) === 0) {
            throw new InvalidArgumentException('refund events require a positive paid_amount');
        }

        $refundStatus = CommerceEventType::refundStatus($eventType);
        if (isset($input['refund_status']) && $input['refund_status'] !== $refundStatus) {
            throw new InvalidArgumentException('refund_status does not match event_type');
        }

        $refundDelta = Money::assertAmount(isset($input['refund_delta']) ? $input['refund_delta'] : null, 'refund_delta');
        $cumulative = Money::assertAmount(
            isset($input['cumulative_refunded_amount']) ? $input['cumulative_refunded_amount'] : null,
            'cumulative_refunded_amount'
        );
        if (Money::toMinor($cumulative) > Money::toMinor($paidAmount)) {
            throw new InvalidArgumentException('cumulative_refunded_amount cannot exceed paid_amount');
        }

        $completionId = self::optionalIdentifier($input, 'completion_id', 128);
        $completionSource = self::optionalIdentifier($input, 'completion_source', 40);
        $providerStatus = self::optionalIdentifier($input, 'provider_status', 40);
        $providerRefundNo = self::optionalIdentifier($input, 'provider_refund_no', 96);

        if ($eventType === CommerceEventType::REFUND_COMPLETED) {
            if ($completionId === '') {
                throw new InvalidArgumentException('completed refund requires completion_id');
            }
            if (!in_array($completionSource, ['balance_transaction', 'provider_query_success', 'manual_finance_confirm'], true)) {
                throw new InvalidArgumentException('completed refund requires a trusted completion_source');
            }
            if ($completionSource === 'provider_query_success' && ($providerStatus !== 'success' || $providerRefundNo === '')) {
                throw new InvalidArgumentException('provider completion requires success status and provider_refund_no');
            }
            if (Money::toMinor($paidAmount) > 0 && Money::toMinor($refundDelta) === 0) {
                throw new InvalidArgumentException('completed refund requires a positive refund_delta');
            }
        } else {
            if ($completionId !== '') {
                throw new InvalidArgumentException('only completed refunds may contain completion_id');
            }
            if (Money::toMinor($refundDelta) !== 0) {
                throw new InvalidArgumentException('non-completed refund requires refund_delta 0.00');
            }
        }

        if ($eventType === CommerceEventType::REFUND_PROCESSING
            && !in_array($completionSource, ['provider_accepted', 'crmeb_refund_status'], true)) {
            throw new InvalidArgumentException('processing refund requires an accepted, non-final completion_source');
        }
        if ($eventType === CommerceEventType::REFUND_PROCESSING && $providerStatus === '') {
            throw new InvalidArgumentException('processing refund requires provider_status evidence');
        }
        if ($eventType !== CommerceEventType::REFUND_PROCESSING
            && $eventType !== CommerceEventType::REFUND_COMPLETED
            && $completionSource !== '') {
            throw new InvalidArgumentException('refund stage does not accept completion_source');
        }

        return [
            'refund_pk' => self::positiveInteger($input, 'refund_pk'),
            'refund_no' => self::identifier($input, 'refund_no', 64),
            'provider_refund_no' => $providerRefundNo,
            'refund_status' => $refundStatus,
            'refund_delta' => $refundDelta,
            'cumulative_refunded_amount' => $cumulative,
            'completion_id' => $completionId,
            'completion_source' => $completionSource,
            'provider_status' => $providerStatus,
        ];
    }

    private static function assertKnownFields(array $input): void
    {
        $allowed = array_flip(array_merge(self::COMMON_FIELDS, self::ORDER_FIELDS, self::REFUND_FIELDS));
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !isset($allowed[$field])) {
                throw new InvalidArgumentException('Unknown commerce event field');
            }
        }
    }

    private static function assertCompatibleFields(array $input, bool $isRefund): void
    {
        $forbidden = $isRefund ? self::ORDER_FIELDS : self::REFUND_FIELDS;
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $input)) {
                throw new InvalidArgumentException('Commerce event contains fields for another event family');
            }
        }
    }

    private static function assertNoPii(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::PII_KEYS, true)) {
                throw new InvalidArgumentException('Commerce events cannot contain PII fields');
            }
            if (is_array($item)) {
                self::assertNoPii($item);
            }
        }
    }

    private static function requiredString(array $input, string $field, int $maxLength): string
    {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be a string', $field));
        }
        $value = trim($input[$field]);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }

        return $value;
    }

    private static function identifier(array $input, string $field, int $maxLength): string
    {
        $value = self::requiredString($input, $field, $maxLength);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]*$/', $value)) {
            throw new InvalidArgumentException(sprintf('%s contains unsupported characters', $field));
        }

        return $value;
    }

    private static function optionalIdentifier(array $input, string $field, int $maxLength): string
    {
        if (!array_key_exists($field, $input) || $input[$field] === '') {
            return '';
        }

        return self::identifier($input, $field, $maxLength);
    }

    private static function positiveInteger(array $input, string $field): int
    {
        if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }

        return $input[$field];
    }

    private static function canonicalJson(array $value): string
    {
        $normalized = self::sortRecursively($value);
        try {
            return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Commerce event cannot be encoded as JSON', 0, $exception);
        }
    }

    private static function sortRecursively(array $value): array
    {
        if (self::isList($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = self::sortRecursively($item);
                }
            }

            return $value;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
