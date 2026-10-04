<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\EventWaitlistService;
use app\chamber\tenancy\TenantContext;
use think\Response;

/** 活动候补：加入/查询/退出候补队列。 */
final class EventWaitlistController
{
    /** @var EventWaitlistService */
    private $waitlist;

    public function __construct(EventWaitlistService $waitlist = null)
    {
        $this->waitlist = $waitlist ?: new EventWaitlistService();
    }

    /** POST /v1/events/{event_id}/waitlist */
    public function join(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $event_id
    ): Response {
        $eventId = $this->positiveId($event_id, 'event_id');
        $body = EventRegistrationRequest::fromArray($this->decodeJsonObject($request));
        // 复用票种解析：直接按 event_id + ticket_id 加入
        $ticketId = $body->ticketId();
        if ($ticketId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'ticket_id must be a positive integer',
                [['field' => 'ticket_id', 'code' => 'invalid_value']]
            );
        }

        return Response::create([
            'status' => 201,
            'msg' => 'created',
            'data' => $this->waitlist->join($tenant, $auth, $ticketId),
        ], 'json', 201);
    }

    /** GET /v1/me/waitlist */
    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        unset($request);
        $items = $this->waitlist->listForMember($tenant, $auth);

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => ['items' => $items]], 'json', 200);
    }

    /** DELETE /v1/me/waitlist/{waitlist_id} */
    public function destroy(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $waitlist_id
    ): Response {
        unset($request);
        $item = $this->waitlist->leave($tenant, $auth, $this->positiveId($waitlist_id, 'waitlist_id'));

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => $item], 'json', 200);
    }

    private function positiveId($value, string $field): int
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
            $field . ' must be a positive integer',
            [['field' => $field, 'code' => 'invalid_value']]
        );
    }

    private function decodeJsonObject(Request $request): array
    {
        $raw = $request->getContent();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Request body must be a JSON object',
                [['field' => 'body', 'code' => 'invalid_value']]
            );
        }

        return $decoded;
    }
}
