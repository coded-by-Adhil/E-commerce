<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;

class IllegalOrderTransition extends ApiHttpException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            409,
            'ILLEGAL_TRANSITION',
            "An order cannot change from {$from->value} to {$to->value}.",
        );
    }
}
