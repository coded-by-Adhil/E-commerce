<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
    ];

    /**
     * Ids of the category with this slug and all its descendants, from one
     * query. Empty for an unknown slug.
     *
     * @return list<int>
     */
    public static function idsWithDescendants(string $slug): array
    {
        $childrenOf = self::query()->get(['id', 'parent_id', 'slug'])->groupBy('parent_id');

        $root = $childrenOf->flatten()->firstWhere('slug', $slug);
        if ($root === null) {
            return [];
        }

        $ids = [];
        $queue = [$root->id];
        while ($queue !== []) {
            $id = array_pop($queue);
            $ids[] = $id;
            foreach ($childrenOf->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
