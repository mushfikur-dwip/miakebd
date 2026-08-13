<?php

namespace App\Models;

use App\Models\Concerns\ResolvesMediaUrls;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Image\Enums\CropPosition;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Slider extends Model implements HasMedia
{
    use InteractsWithMedia, ResolvesMediaUrls;

    protected $table = "sliders";
    protected $fillable = ['title', 'link', 'description', 'status'];
    protected $casts = [
        'id'          => 'integer',
        'title'       => 'string',
        'description' => 'string',
        'status'      => 'integer',
        'link'        => 'string',
    ];

    /**
     * The hero banner. This returned getUrl('cover') unconditionally, so a
     * slide whose 1689x600 cover conversion never generated - the largest
     * conversion in the app, and the first to die on a memory limit - served a
     * URL that 404s. The placeholder below could not save it either, because
     * the media row exists and the collection is not empty.
     */
    public function getImageAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('slider')->last(),
            'cover',
            'images/default/slider.png'
        );
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('cover')->fit(Fit::Fill, 1689, 600)->keepOriginalImageFormat()->sharpen(10);
    }
}
