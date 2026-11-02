<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'stock' => 'integer',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ---------- Media ----------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->fit(Fit::Crop, 300, 300);
    }
}
