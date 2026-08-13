<?php

namespace App\Models;


use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use App\Models\Concerns\ResolvesMediaUrls;
use Spatie\Image\Enums\CropPosition;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\Conversions\Manipulations;
use Spatie\MediaLibrary\MediaCollections\Models\Media;


class Promotion extends Model implements HasMedia
{
    use InteractsWithMedia;
    use ResolvesMediaUrls;

    protected $table = "promotions";
    protected $fillable = ['name', 'slug', 'type', 'status'];
    protected $casts = [
        'id'     => 'integer',
        'name'   => 'string',
        'slug'   => 'string',
        'type'   => 'integer',
        'status' => 'integer'
    ];


    public function getCoverAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('promotion')->last(),
            'cover',
            'images/default/promotion/cover.png'
        );
    }

    public function getPreviewAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('promotion')->last(),
            'preview',
            'images/default/promotion/preview.png'
        );
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('cover')->fit(Fit::Fill, 540, 336)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('preview')->width(1689)->height(600)->keepOriginalImageFormat()->sharpen(10);
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    public function promotionProducts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PromotionProduct::class, 'promotion_id', 'id');
    }
}
