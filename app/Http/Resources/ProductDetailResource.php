<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects category, variants and images to be eager loaded.
 *
 * @mixin Product
 */
class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
            'variants' => VariantResource::collection($this->variants),
            'images' => ProductImageResource::collection($this->images),
        ];
    }
}
