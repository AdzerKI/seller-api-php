<?php

namespace Opotopo\SellerApi\Orders;

use InvalidArgumentException;

final class MissingTrackingNumber extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The fulfillment needs a tracking number');
    }
}
