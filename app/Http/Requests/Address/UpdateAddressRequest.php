<?php

namespace App\Http\Requests\Address;

// PUT replaces the address, so it takes the same fields and rules as create.
class UpdateAddressRequest extends StoreAddressRequest {}
