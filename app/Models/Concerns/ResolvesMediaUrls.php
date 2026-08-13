<?php

namespace App\Models\Concerns;

use App\Support\MediaUrl;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Media URL resolution shared by every model that exposes an image accessor.
 *
 * This logic was written for Product and left there. Slider, ProductCategory,
 * ProductBrand, Benefit, Promotion, ProductSeo and User all kept the original
 * shape - `getMedia(...)->first()->getUrl('cover')` with a placeholder branch
 * that only runs when the collection is empty - so all of them carried the
 * same two faults Product had. The hero slider showing an empty grey box is
 * exactly that: a `cover` conversion that was never generated, returning a URL
 * that 404s, with the placeholder never reached because a media row does exist.
 */
trait ResolvesMediaUrls
{
    /**
     * Resolve a conversion URL, falling back to the original upload.
     *
     * getUrl('cover') builds a path whether or not that file was ever written,
     * so a conversion that failed to generate produces a 404 and a broken-image
     * icon with no fallback - the collection is not empty, so the placeholder
     * branch never runs. hasGeneratedConversion() reads the media row's
     * already-loaded JSON column, so this costs no disk I/O on listing pages.
     *
     * A file that is missing from disk despite being marked generated is handled
     * client-side by the @error placeholder swap, which needs no stat() call.
     */
    protected function conversionUrl(?Media $media, string $conversion, string $fallback): string
    {
        if (!$media) {
            return asset($fallback);
        }

        if ($media->hasGeneratedConversion($conversion)) {
            return self::encodeMediaUrl($media->getUrl($conversion));
        }

        return self::encodeMediaUrl($media->getUrl());
    }

    /** @see MediaUrl::encode() for why this is needed at all. */
    protected static function encodeMediaUrl(string $url): string
    {
        return MediaUrl::encode($url);
    }
}
