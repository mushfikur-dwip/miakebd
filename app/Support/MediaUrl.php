<?php

namespace App\Support;

class MediaUrl
{
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
