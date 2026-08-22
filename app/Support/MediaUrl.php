<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaUrl
{
    /**
     * Pixel dimensions of a media file, as [width, height], or null if they
     * cannot be read.
     *
     * og:image:width and og:image:height were only ever emitted for the brand
     * card, whose size is a constant in the layout. A product photo is
     * whatever the admin uploaded, so the size was left off - but leaving it
     * off is what WhatsApp reacts worst to: it will not download an image to
     * find out how big it is before laying out the card, so a product link
     * rendered as title and description with no picture, while the same page
     * previewed fine on platforms that do fetch first.
     *
     * Read from the generated file rather than guessed, so the declared size
     * is the real one - a wrong size is worse than none.
     *
     * Cached forever against the media id: replacing an image creates a new
     * media row and therefore a new key, so this can never go stale.
     */
    public static function dimensions(?Media $media, string $conversion): ?array
    {
        if (!$media) {
            return null;
        }

        $useConversion = $media->hasGeneratedConversion($conversion);

        return Cache::rememberForever(
            'media-dimensions:' . $media->id . ':' . ($useConversion ? $conversion : 'original'),
            function () use ($media, $conversion, $useConversion) {
                try {
                    $path = $useConversion ? $media->getPath($conversion) : $media->getPath();
                } catch (\Throwable) {
                    // Disk not configured, or the media row points at a file
                    // that is gone. Not worth failing a page render over.
                    return null;
                }

                if (!is_file($path)) {
                    return null;
                }

                $size = @getimagesize($path);

                return ($size && $size[0] > 0 && $size[1] > 0) ? [$size[0], $size[1]] : null;
            }
        );
    }

    /**
     * Percent-encode the path of a media URL.
     *
     * Spatie builds media URLs by concatenating the stored file name straight
     * into the path, unencoded. 125 of this catalogue's images carry
     * characters that cannot survive that: 86 contain "&" and 59 contain
     * non-ASCII (em dashes, mostly, from pasted marketing copy), and plenty
     * contain spaces. The browser and the server then disagree about what was
     * requested and the image 404s.
     *
     * This matters twice over for social previews. A browser will often repair
     * a sloppy URL; WhatsApp's and Facebook's crawlers will not - they fetch
     * og:image exactly as written, once, and if it does not resolve the card
     * renders with no image at all. That is why a shared product link showed
     * its title and description but no picture.
     *
     * Only the path is touched; scheme, host, port and query are preserved,
     * and the slashes between segments are kept as separators. Safe to apply
     * to a clean URL, and there is no double-encoding risk because Spatie does
     * no encoding of its own - but do not hand it a URL you have already
     * encoded by hand, or "%20" becomes "%2520".
     */
    public static function encode(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['path'])) {
            return $url;
        }

        $path = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

        $prefix = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $prefix = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $prefix .= ':' . $parts['port'];
            }
        }

        $suffix = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $prefix . $path . $suffix;
    }
}
