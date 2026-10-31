<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List representation. Expects the aggregates and relations loaded by
 * ProductController::index (price_from, stock_total, category, thumbnail).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $priceFrom = $this->getAttribute('price_from');
        $image = $this->thumbnail;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price_from' => $priceFrom === null ? null : (int) $priceFrom,
            'currency' => config('shop.currency'),
            'in_stock' => (int) $this->getAttribute('stock_total') > 0,
            'image' => $image === null ? null : [
                'path' => $image->path,
                'alt_text' => $image->alt_text,
            ],
            'category' => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
        ];
    }
}
