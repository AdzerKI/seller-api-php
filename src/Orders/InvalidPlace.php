<?php

namespace Opotopo\SellerApi\Orders;

use InvalidArgumentException;

final class InvalidPlace extends InvalidArgumentException
{
    public function __construct(public readonly int $number)
    {
        parent::__construct('Place '.$number.' needs the weight and all three dimensions');
    }
}
