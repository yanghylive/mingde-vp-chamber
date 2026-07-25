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

        $id = (int) $row['id'];
        $graduationYear = (int) $row['graduation_year'];
        $graduationAt = (int) $row['graduation_time'];
        $submittedAt = (int) $row['submit_time'];
        $reviewedAt = (int) $row['review_time'];
        $status = GraduateVerificationState::fromDatabase((int) $row['status']);
        $applicationNumber = (string) ($row['apply_no'] ?? '');
        $className = (string) ($row['class_name'] ?? '');
        $reviewNote = (string) ($row['review_note'] ?? '');
        $classNameLength = function_exists('mb_strlen') ? mb_strlen($className, 'UTF-8') : strlen($className);
        $reviewNoteLength = function_exists('mb_strlen') ? mb_strlen($reviewNote, 'UTF-8') : strlen($reviewNote);
        if ($id <= 0 || $applicationNumber === '' || strlen($applicationNumber) > 32
            || $classNameLength > 80 || $reviewNoteLength > 500
            || $graduationYear < 1900 || $graduationYear > 2106
            || $graduationAt < 0 || $submittedAt < 0 || $reviewedAt < 0
            || $graduationAt > 4294967295 || $submittedAt > 4294967295 || $reviewedAt > 4294967295) {
            throw new RuntimeException('Graduate verification row snapshot is invalid');
        }

        return [
            'id' => $id,
            'application_no' => $applicationNumber,
            'status' => $status,
            'class_name' => $className,
            'graduation_year' => $graduationYear,
            'graduation_at' => $graduationAt,
            'proof_object_keys' => array_values($proofObjectKeys),
            'proof_assets' => [],
            'submitted_at' => $submittedAt,
            'reviewed_at' => $reviewedAt,
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
