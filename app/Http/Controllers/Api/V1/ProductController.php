<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductIndexRequest;
use App\Http\Resources\ProductCollection;
use App\Models\Category;
use App\Models\Product;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): ProductCollection
    {
        $query = Product::query()
            ->active()
            ->with(['category:id,name,slug', 'thumbnail'])
            ->withMin('variants as price_from', 'price')
            ->withSum('variants as stock_total', 'stock');

        if (is_string($slug = $request->validated('category'))) {
            $query->whereIn('category_id', Category::idsWithDescendants($slug));
        }

        if (is_string($term = $request->validated('q'))) {
            $query->nameContains($term);
        }

        match ($request->sort()) {
            'price' => $query->orderBy('price_from')->orderBy('id'),
            '-price' => $query->orderByDesc('price_from')->orderByDesc('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return new ProductCollection($query->paginate($request->perPage()));
    }
}
