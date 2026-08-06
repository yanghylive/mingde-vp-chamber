<?php

declare(strict_types=1);

namespace app\chamber\assets;

use app\chamber\exceptions\MemberTransactionException;
use think\Response;

final class MemberAssetContentResponder
{
    public static function parseOwnerQuery(array $query, string $rawQuery = ''): bool
    {
        self::assertNoDuplicateFields($rawQuery);
        self::assertAllowedFields($query, ['download']);

        return self::parseDownload($query);
    }

    public static function parseAdminQuery(array $query, string $rawQuery = ''): array
    {
        self::assertNoDuplicateFields($rawQuery);
        self::assertAllowedFields($query, ['application_id', 'download']);
        if (!array_key_exists('application_id', $query)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'application_id is required',
                [['field' => 'application_id', 'code' => 'required']]
            );
        }

        $applicationId = $query['application_id'];
        if (is_string($applicationId) && preg_match('/^[1-9][0-9]*$/D', $applicationId) === 1) {
            $integer = (int) $applicationId;
            if ((string) $integer === $applicationId) {
                $applicationId = $integer;
            }
        }
        if (!is_int($applicationId) || $applicationId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'application_id must be a positive integer',
                [['field' => 'application_id', 'code' => 'invalid_value']]
            );
        }

        return [
            'application_id' => $applicationId,
            'download' => self::parseDownload($query),
        ];
    }

    public static function respond(MemberAssetContent $content, bool $download): Response
    {
        return (new PrivateMemberAssetResponse($content->path()))
            ->name($content->originalName(), false)
            ->mimeType($content->mimeType())
            ->force($download)
            ->expire(0);
    }

    private static function assertAllowedFields(array $query, array $allowed): void
    {
        foreach (array_keys($query) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new MemberTransactionException(
                    422,
                    'request_validation_failed',
                    'Unknown member asset content query parameter',
                    [['field' => (string) $field, 'code' => 'unknown_field']]
                );
            }
        }
    }

    private static function assertNoDuplicateFields(string $rawQuery): void
    {
        if ($rawQuery === '') {
            return;
        }
        $seen = [];
        foreach (explode('&', $rawQuery) as $part) {
            $field = urldecode(explode('=', $part, 2)[0]);
            if (isset($seen[$field])) {
                throw new MemberTransactionException(
                    422,
                    'request_validation_failed',
                    'Duplicate member asset content query parameter',
                    [['field' => $field, 'code' => 'duplicate_field']]
                );
            }
            $seen[$field] = true;
        }
    }

    private static function parseDownload(array $query): bool
    {
        $download = $query['download'] ?? '0';
        if ($download !== '0' && $download !== '1' && $download !== 0 && $download !== 1) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'download must be 0 or 1',
                [['field' => 'download', 'code' => 'invalid_value']]
            );
        }

        return $download === '1' || $download === 1;
    }
}
