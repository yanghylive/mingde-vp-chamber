<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;

final class GuardedStoreOrderCreateServices extends StoreOrderCreateServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function createOrder(
        $uid,
        $key,
        $userInfo,
        $addressId,
        $payType,
        $useIntegral = false,
        $couponId = 0,
        $mark = '',
        $combinationId = 0,
        $pinkId = 0,
        $seckillId = 0,
        $bargainId = 0,
        $shippingType = 1,
        $real_name = '',
        $phone = '',
        $storeId = 0,
        $news = false,
        $advanceId = 0,
        $customForm = [],
        $invoice_id = 0,
        $is_gift = 0,
        $gift_mark = ''
    ) {
        /** @var StoreOrderServices $orders */
        $orders = app()->make(StoreOrderServices::class);
        $cartGroup = $orders->getCacheOrderInfo((int) $uid, (string) $key);
        if (is_array($cartGroup)) {
            $this->membershipGuard->assertCartGroupAllowed($cartGroup);
        }

        return parent::createOrder(
            $uid,
            $key,
            $userInfo,
            $addressId,
            $payType,
            $useIntegral,
            $couponId,
            $mark,
            $combinationId,
            $pinkId,
            $seckillId,
            $bargainId,
            $shippingType,
            $real_name,
            $phone,
            $storeId,
            $news,
            $advanceId,
            $customForm,
            $invoice_id,
            $is_gift,
            $gift_mark
        );
    }
}
