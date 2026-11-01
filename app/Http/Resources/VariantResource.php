<?php

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes the stock number, only whether any is left.
 *
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => config('shop.currency'),
            'in_stock' => $this->stock > 0,
        ];
    }
}
