<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\MemberProfilePatch;
use app\chamber\services\MemberProfileService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class MemberProfileController
{
    /** @var MemberProfileService */
    private $service;

    public function __construct(MemberProfileService $service)
    {
        $this->service = $service;
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        return $this->success($this->service->getProfile($tenant, $auth));
    }

    public function update(
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

        try {
            $input = $this->decodeJsonObject($request);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        $patch = MemberProfilePatch::fromArray($input);

        return $this->success($this->service->updateProfile(
            $tenant,
            $auth,
            $patch,
            $callerKey
        ));
    }

    private function success(array $data): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }

    private function decodeJsonObject(Request $request): array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        $contentType = trim(explode(';', $contentType, 2)[0]);
        if ($contentType !== 'application/json' && substr($contentType, -5) !== '+json') {
            throw new InvalidArgumentException('Content-Type must be application/json');
        }

        $raw = $request->getContent();
        if (!is_string($raw) || strlen($raw) > 32768 || trim($raw) === '') {
            throw new InvalidArgumentException('Request body must be a JSON object of at most 32768 bytes');
        }

        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }
        foreach (['resources', 'needs', 'interests', 'expertise'] as $listField) {
            if (property_exists($object, $listField) && !is_array($object->{$listField})) {
                throw new InvalidArgumentException(sprintf('%s must be a JSON array', $listField));
            }
        }
        if (property_exists($object, 'privacy') && !$object->privacy instanceof stdClass) {
            throw new InvalidArgumentException('privacy must be a JSON object');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }

        return $decoded;
    }
}
