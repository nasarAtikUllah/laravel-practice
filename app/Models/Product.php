<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property ProductStatus $status
 */
class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'brand_id',
        'collection_id',
        'name',
        'slug',
        'description',
        'status',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return ['status' => ProductStatus::class];
    }

    protected static function booted(): void
    {
        static::deleting(function (Product $product) {
            if ($product->isForceDeleting()) {
                return;
            }

            // Free the slug so the same product can be created again.
            $product->forceFill([
                'slug' => Str::limit($product->slug, 200, '').'-deleted-'.$product->id,
            ])->saveQuietly();

            // One by one, so each variant's own deleting event frees its SKU.
            $product->variants()->get()->each->delete();
        });
    }

    // ---------- Relations ----------

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    // ---------- Scopes ----------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Published->value);
    }

    /** What the public website may show. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [ProductStatus::Published->value, ProductStatus::Upcoming->value])
            ->whereHas('brand', fn ($q) => $q->where('is_active', true))
            ->whereHas('collection', fn ($q) => $q->where('is_active', true));
    }

    // ---------- Media ----------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured_image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->fit(Fit::Crop, 300, 300);
    }
}
