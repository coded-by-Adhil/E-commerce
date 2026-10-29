<?php

namespace App\Enums;

enum StockReason: string
{
    case Restock = 'restock';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case Cancellation = 'cancellation';
}
