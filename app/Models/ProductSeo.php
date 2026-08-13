<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use App\Models\Concerns\ResolvesMediaUrls;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductSeo extends Model implements HasMedia
{
    use InteractsWithMedia;
    use ResolvesMediaUrls;

    protected $table = 'product_seos';

    protected $fillable = ['product_id', 'title', 'description', 'meta_keyword'];

    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'title' => 'string',
        'description' => 'string',
        'meta_keyword' => 'string',
    ];

    public function getThumbAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('product-seo')->last(),
            'thumb',
            'images/default/seo/thumb.png'
        );
    }

    /**
     * This is what a product page hands to og:image, so a 404 here is the
     * difference between a link preview with a picture and one without.
     */
    public function getCoverAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('product-seo')->last(),
            'cover',
            'images/default/seo/cover.png'
        );
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Fill, 112, 72)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('cover')->width(600)->keepOriginalImageFormat()->sharpen(10);
    }
}
