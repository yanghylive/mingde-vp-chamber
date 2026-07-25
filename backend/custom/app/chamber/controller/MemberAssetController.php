<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\assets\MemberAssetContentResponder;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberAssetService;
use app\chamber\tenancy\TenantContext;
use think\file\UploadedFile;
use think\Response;
use Throwable;

final class MemberAssetController
{
    /** @var MemberAssetService */
    private $service;

    public function __construct(MemberAssetService $service)
    {
        $this->service = $service;
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $callerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($callerKey === '') {
            throw new MemberTransactionException(
                400,
                'idempotency_key_required',
                'Idempotency-Key header is required'
            );
        }
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'multipart/form-data') {
            throw new MemberTransactionException(
                415,
                'unsupported_media_type',
                'Content-Type must be multipart/form-data'
            );
        }

        $fields = (array) $request->post();
        foreach (array_keys($fields) as $field) {
            if ($field !== 'purpose') {
                throw new MemberTransactionException(
                    422,
                    'request_validation_failed',
                    'Unknown member asset upload field',
                    [['field' => (string) $field, 'code' => 'unknown_field']]
                );
            }
        }

        try {
            $files = $request->file();
        } catch (Throwable $exception) {
            throw new MemberTransactionException(
                422,
                'asset_upload_invalid',
                'file could not be accepted as an HTTP upload',
                [['field' => 'file', 'code' => 'invalid_value']]
            );
        }
        if (!is_array($files) || array_keys($files) !== ['file'] || !$files['file'] instanceof UploadedFile) {
            throw new MemberTransactionException(
                422,
                'asset_upload_invalid',
                'exactly one file field is required',
                [['field' => 'file', 'code' => 'required']]
            );
        }

        $metadata = $this->service->upload(
            $tenant,
            $auth,
            $files['file'],
            $fields['purpose'] ?? null,
            $callerKey
        );

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $metadata,
        ], 'json', 201);
    }

    public function content(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $asset_id
    ): Response {
        $download = MemberAssetContentResponder::parseOwnerQuery(
            (array) $request->get(),
            $request->query()
        );
        $content = $this->service->contentForOwner(
            $tenant,
            $auth,
            $this->positiveId($asset_id)
        );

        return MemberAssetContentResponder::respond($content, $download);
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                return $integer;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            'asset_id must be a positive integer',
            [['field' => 'asset_id', 'code' => 'invalid_value']]
        );
    }
}
