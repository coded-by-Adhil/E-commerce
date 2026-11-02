<?php

namespace App\Exceptions;

class InsufficientStockException extends ApiHttpException
{
    /**
     * @param  list<int>  $variantIds
     */
    public function __construct(array $variantIds)
    {
        parent::__construct(409, 'INSUFFICIENT_STOCK', 'Not enough stock for one or more items.', [
            'variant_ids' => $variantIds,
        ]);
    }
}
