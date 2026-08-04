<?php

declare(strict_types=1);

namespace app\chamber\verification;

use app\chamber\membership\GraduateVerificationState;
use RuntimeException;

final class GraduateVerificationApplication
{
    public static function fromDatabaseRow(array $row): array
    {
        foreach (['id', 'status', 'graduation_year', 'graduation_time', 'submit_time', 'review_time'] as $field) {
            if (!array_key_exists($field, $row) || !is_numeric($row[$field])) {
                throw new RuntimeException(sprintf('Graduate verification row field %s is invalid', $field));
            }
        }

        $proofObjectKeys = json_decode((string) ($row['proof_json'] ?? ''), true);
        if (!is_array($proofObjectKeys) || json_last_error() !== JSON_ERROR_NONE
            || !self::isList($proofObjectKeys) || count($proofObjectKeys) > 10) {
            throw new RuntimeException('Graduate verification proof snapshot is invalid');
        }
        foreach ($proofObjectKeys as $index => $key) {
            try {
                GraduateVerificationSubmission::assertObjectStorageKey(
                    $key,
                    sprintf('proof_object_keys[%d]', $index)
                );
            } catch (GraduateVerificationValidationException $exception) {
                throw new RuntimeException('Graduate verification proof snapshot is invalid');
            }
        }

        $status = GraduateVerificationState::fromDatabase((int) $row['status']);
        $applicationNumber = (string) ($row['apply_no'] ?? '');
        $className = (string) ($row['class_name'] ?? '');
        $reviewNote = (string) ($row['review_note'] ?? '');
        if ($applicationNumber === '' || strlen($applicationNumber) > 32 || strlen($className) > 320
            || strlen($reviewNote) > 2000) {
            throw new RuntimeException('Graduate verification row snapshot is invalid');
        }

        return [
            'id' => (int) $row['id'],
            'application_no' => $applicationNumber,
            'status' => $status,
            'class_name' => $className,
            'graduation_year' => (int) $row['graduation_year'],
            'graduation_at' => (int) $row['graduation_time'],
            'proof_object_keys' => array_values($proofObjectKeys),
            'submitted_at' => (int) $row['submit_time'],
            'reviewed_at' => (int) $row['review_time'],
            'review_note' => $reviewNote,
            'can_resubmit' => in_array($status, [
                GraduateVerificationState::RETURNED,
                GraduateVerificationState::REJECTED,
                GraduateVerificationState::REVOKED,
            ], true),
        ];
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
