<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * The whole tree from a single query; nesting is built in memory.
     */
    public function index(): AnonymousResourceCollection
    {
        $byParent = Category::query()
            ->select(['id', 'parent_id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        foreach ($byParent as $siblings) {
            foreach ($siblings as $category) {
                $category->setRelation('children', $byParent->get($category->id, collect()));
            }
        }

        return CategoryResource::collection($byParent->get('', collect()));
    }
}
