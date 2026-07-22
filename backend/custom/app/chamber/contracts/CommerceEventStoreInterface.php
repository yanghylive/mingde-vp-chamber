<?php

namespace app\chamber\contracts;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;

interface CommerceEventStoreInterface
{
    public function record(CommerceEvent $event): CommerceEventReceipt;
}
