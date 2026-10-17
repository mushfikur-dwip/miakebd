<?php

namespace App\Models;

use App\Enums\SliderPosition;
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
    protected $fillable = ['title', 'link', 'position', 'description', 'status'];
    protected $casts = [
        'id'          => 'integer',
        'title'       => 'string',
        'description' => 'string',
        'status'      => 'integer',
        'position'    => 'integer',
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

    /**
     * The small banner used by the GRID row.
     *
     * A grid tile is roughly a third the width of the hero, so serving it the
     * 1689px cover wastes most of the bytes on a phone. Rows uploaded before
     * this conversion existed have no `tile` file on disk, and conversionUrl
     * falls back to the original for those rather than 404ing.
     */
    public function getTileAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('slider')->last(),
            'tile',
            'images/default/slider.png'
        );
    }

    /**
     * The hero's phone version, or null when the slide has none.
     *
     * A hero banner is a 1689x600 desktop design, so on a phone the whole
     * picture fits only at ~130px tall. The phone image is a separate 2:1
     * design the storefront shows below 640px instead; slides without one
     * show the desktop banner cropped to 2:1 from the left.
     */
    public function getMobileImageAttribute(): ?string
    {
        $media = $this->getMedia('slider-mobile')->last();

        return $media ? $this->conversionUrl($media, 'mobile', 'images/default/slider.png') : null;
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Desktop sizes for the desktop upload only: a phone image never
        // needs a 1689px cover or a grid tile.
        $this->addMediaConversion('cover')->fit(Fit::Fill, 1689, 600)->keepOriginalImageFormat()->sharpen(10)
            ->performOnCollections('slider');
        $this->addMediaConversion('tile')->fit(Fit::Fill, 540, 336)->keepOriginalImageFormat()->sharpen(10)
            ->performOnCollections('slider');
        // Fill, like `cover`: an upload of the wrong shape is padded, never
        // cut, so the words on a banner always survive.
        $this->addMediaConversion('mobile')->fit(Fit::Fill, 1200, 600)->keepOriginalImageFormat()->sharpen(10)
            ->performOnCollections('slider-mobile');
    }

    /** Every position except HERO belongs to the banner block. */
    public function isBanner(): bool
    {
        return $this->position !== SliderPosition::HERO;
    }
}
