<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\MemberBootstrapRequest;
use app\chamber\services\MemberBootstrapService;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use stdClass;
use think\Response;

final class MemberBootstrapController
{
    /** @var MemberBootstrapService */
    private $service;

    public function __construct(MemberBootstrapService $service)
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

        try {
            $bootstrapRequest = MemberBootstrapRequest::fromArray($this->decodeJsonObject($request));
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                $exception->getMessage(),
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        $data = $this->service->bootstrap(
            $tenant,
            $auth,
            $bootstrapRequest,
            $callerKey,
            [
                'ip' => (string) $request->ip(),
                'user_agent' => (string) $request->header('User-Agent', ''),
                'correlation_id' => isset($request->correlationId)
                    ? (string) $request->correlationId
                    : '',
            ]
        );

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
        if (strlen($raw) > 32768) {
            throw new InvalidArgumentException('Request body must be a JSON object of at most 32768 bytes');
        }
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('Request body must be a JSON object of at most 32768 bytes');
        }
        $object = json_decode($raw);
        if (!$object instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }
        if (property_exists($object, 'consents')) {
            if (!is_array($object->consents)) {
                throw new InvalidArgumentException('consents must be a JSON array');
            }
            foreach ($object->consents as $consent) {
                if (!$consent instanceof stdClass) {
                    throw new InvalidArgumentException('Each consent must be a JSON object');
                }
            }
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Request body must be a valid JSON object');
        }

        return $decoded;
    }
}
