<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\StoreOrderSuccessServices;
use app\services\pay\PayServices;

/** Routes Chamber membership payments to the trusted entitlement adapter. */
final class GuardedStoreOrderSuccessServices extends StoreOrderSuccessServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    /** @var MembershipPaymentCompletionService */
    private $payments;

    public function __construct(
        StoreOrderDao $dao,
        MembershipNativeOrderGuard $membershipGuard,
        MembershipPaymentCompletionService $payments
    ) {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
        $this->payments = $payments;
    }

    public function paySuccess(array $orderInfo, string $paytype = PayServices::WEIXIN_PAY, array $other = [])
    {
        if (!$this->membershipGuard->isMembershipOrder($orderInfo)) {
            return parent::paySuccess($orderInfo, $paytype, $other);
        }

        return $this->payments->complete($orderInfo, $paytype, $other, false);
    }

    public function zeroYuanPayment(array $orderInfo, int $uid, string $payType = PayServices::YUE_PAY)
    {
        if (!$this->membershipGuard->isMembershipOrder($orderInfo)) {
            return parent::zeroYuanPayment($orderInfo, $uid, $payType);
        }

        if ((int) ($orderInfo['uid'] ?? 0) !== $uid) {
            return false;
        }

        return $this->payments->complete($orderInfo, $payType, [], true);
    }
}
