<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * The first image (lowest position), eager-loadable in one query.
     *
     * @return HasOne<ProductImage, $this>
     */
    public function thumbnail(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['position' => 'min', 'id' => 'min']);
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }

    /**
     * Name LIKE %term%, with LIKE wildcards in the term escaped.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function nameContains(Builder $query, string $term): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
    }
}
