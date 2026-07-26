<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderRefundDao;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;

/**
 * Keeps CRMEB's separate refund service from exposing an unbound membership
 * order through refund list/detail or cancellation lookups.
 */
final class GuardedStoreOrderRefundServices extends StoreOrderRefundServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(
        StoreOrderRefundDao $dao,
        StoreOrderServices $storeOrderServices,
        MembershipNativeOrderGuard $membershipGuard
    ) {
        parent::__construct($dao, $storeOrderServices);
        $this->membershipGuard = $membershipGuard;
    }

    public function get($id, ?array $field = [], ?array $with = [])
    {
        $refund = $this->dao->get($id, $field, $with);
        $this->assertRefundAllowed($refund);

        return $refund;
    }

    public function refundList($where)
    {
        $result = parent::refundList($where);
        foreach (($result['list'] ?? []) as $refund) {
            $this->assertRefundAllowed($refund);
        }

        return $result;
    }

    public function refundDetail($uni)
    {
        $refund = $this->dao->get(['order_id' => $uni], ['store_order_id']);
        $this->assertRefundAllowed($refund);

        return parent::refundDetail($uni);
    }

    public function value($where, ?string $field = '')
    {
        $refund = $this->dao->getOne(is_array($where) ? $where : []);
        $this->assertRefundAllowed($refund);

        return $this->dao->value($where, $field);
    }

    public function update($id, array $data, ?string $field = '')
    {
        $refund = is_array($id) ? $this->dao->getOne($id) : $this->dao->get($id);
        $this->assertRefundAllowed($refund);

        return $this->dao->update($id, $data, $field);
    }

    public function editRefundExpress($data)
    {
        $refundId = (int) (is_array($data) ? ($data['id'] ?? 0) : 0);
        if ($refundId > 0) {
            $this->assertRefundAllowed($this->dao->get($refundId));
        }

        return parent::editRefundExpress($data);
    }

    public function cancelOrderRefundCartInfo(int $id, int $oid, $orderRefundInfo = [], string $title = '')
    {
        $this->membershipGuard->assertNativeReadAllowed(['id' => $oid]);

        return parent::cancelOrderRefundCartInfo($id, $oid, $orderRefundInfo, $title);
    }

    /** @param mixed $refund */
    private function assertRefundAllowed($refund): void
    {
        if (is_object($refund) && method_exists($refund, 'toArray')) {
            $refund = $refund->toArray();
        }
        if (!is_array($refund)) {
            return;
        }

        $orderId = (int) ($refund['store_order_id'] ?? 0);
        if ($orderId > 0) {
            $this->membershipGuard->assertNativeReadAllowed(['id' => $orderId]);
        }
    }
}
