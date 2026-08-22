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
     * branch never runs.
     *
     * hasGeneratedConversion() alone is not enough: it reads a flag off the
     * media row, and the flag outlives the file. That is what left the product
     * grid full of "No Image Available" while the same product's detail gallery
     * looked fine - the grid asks for `cover` and the gallery asks for
     * `preview`, and only the cover files had gone missing. So the file is
     * checked as well, and the original is served when the conversion is not
     * actually on disk.
     */
    protected function conversionUrl(?Media $media, string $conversion, string $fallback): string
    {
        if (!$media) {
            return asset($fallback);
        }

        if ($media->hasGeneratedConversion($conversion) && self::conversionFileExists($media, $conversion)) {
            return self::encodeMediaUrl($media->getUrl($conversion));
        }

        return self::encodeMediaUrl($media->getUrl());
    }

    /**
     * Is the generated file actually there?
     *
     * A local stat, memoised per media id and conversion for the life of the
     * request, so a listing that renders the same product twice pays once. The
     * media disk is `local`, so this is a filesystem stat the OS has already
     * cached - not a network round trip. If the media disk is ever moved to
     * S3 this check has to go behind a driver test, or every image URL becomes
     * an API call.
     */
    private static function conversionFileExists(Media $media, string $conversion): bool
    {
        static $checked = [];

        $key = $media->id . ':' . $conversion;

        if (!array_key_exists($key, $checked)) {
            try {
                $checked[$key] = is_file($media->getPath($conversion));
            } catch (\Throwable) {
                // Disk not configured, or a media row pointing at nothing.
                // Fall back to the original rather than fail a page render.
                $checked[$key] = false;
            }
        }

        return $checked[$key];
    }

    /** @see MediaUrl::encode() for why this is needed at all. */
    protected static function encodeMediaUrl(string $url): string
    {
        return MediaUrl::encode($url);
    }
}
