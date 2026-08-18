<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\assets\MemberAssetContentResponder;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\MemberAssetService;
use app\chamber\tenancy\TenantContext;
use think\Response;

final class MemberAssetAdminController
{
    /** @var MemberAssetService */
    private $service;

    public function __construct(MemberAssetService $service)
    {
        $this->service = $service;
    }

    public function content(
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        $asset_id
    ): Response {
        $admin->assertPermission('chamber.member.read');
        $query = MemberAssetContentResponder::parseAdminQuery(
            (array) $request->get(),
            $request->query()
        );
        $content = $this->service->contentForAdmin(
            $tenant,
            $admin,
            $this->positiveId($asset_id),
            $query['application_id']
        );

        return MemberAssetContentResponder::respond($content, $query['download']);
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
