<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property-read int $available
 */
class ProductVariant extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'product_id',
        'title',
        'sku',
        'price',
        'compare_price',
        'stock',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'stock' => 'integer',
            'reserved' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ProductVariant $variant) {
            if ($variant->isForceDeleting()) {
                return;
            }

            // Free the SKU so it can be used again.
            $variant->forceFill([
                'sku' => Str::limit($variant->sku, 80, '').'-DELETED-'.$variant->id,
            ])->saveQuietly();
        });
    }

    // ---------- Relations ----------

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    // ---------- Media ----------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->fit(Fit::Crop, 300, 300);
    }

    /** What can still be sold: on hand minus held by pending orders. */
    protected function available(): Attribute
    {
        return Attribute::get(fn () => max(0, $this->stock - $this->reserved));
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
