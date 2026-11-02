<?php

return [

    /*
    | Single-currency shop (D1). Prices are integer minor units in this currency.
    */
    'currency' => env('SHOP_CURRENCY', 'USD'),

    /*
    | Flat shipping added to every order, in minor units (500 = 5.00). 0 means free shipping.
    */
    'shipping_flat' => (int) env('SHOP_SHIPPING_FLAT', 500),

];
