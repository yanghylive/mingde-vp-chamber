<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\EventService;
use app\chamber\tenancy\TenantContext;
use think\Response;

final class EventController
{
    /** @var EventService */
    private $service;

    public function __construct(EventService $service)
    {
        $this->service = $service;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $filters = EventListQuery::fromArray((array) $request->get());

        return $this->response($this->service->list($tenant, $auth, $filters));
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $event_id
    ): Response {
        unset($request);

        return $this->response($this->service->detail(
            $tenant,
            $auth,
            $this->positiveId($event_id)
        ));
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                return $parsed;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            'event_id must be a positive integer',
            [['field' => 'event_id', 'code' => 'invalid_value']]
        );
    }

    private function response(array $data): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
