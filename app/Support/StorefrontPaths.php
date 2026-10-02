<?php

namespace App\Support;

/**
 * Which addresses the storefront SPA really has, for requests that reach the
 * server's fallback route.
 *
 * The SPA renders its own "not found" screen for anything else, but the server
 * used to answer every unknown address with 200 and "index, follow" - soft
 * 404s Google could index, old WooCommerce links included - and served the
 * checkout and account screens as indexable pages.
 *
 * Paths with their own web routes (/product, /product-category, /brand, /blog,
 * /offers, /most-popular, /login) never get here. StorefrontNotFoundTest reads
 * every path in resources/js/router and fails if one is missing from both.
 */
class StorefrontPaths
{
    public const INDEX   = 'index';
    public const NOINDEX = 'noindex';
    public const MISSING = 'missing';

    /** Public screens. */
    private const INDEXED = ['', 'flash-sale'];

    /** Public screens addressed by one slug; whether the record exists is decided elsewhere. */
    private const INDEXED_BY_SLUG = ['page', 'promotion', 'product-section', 'campaign'];

    /** Real screens with nothing to rank for. `home` is the SPA's old alias of /. */
    private const PRIVATE = ['home', 'wishlist', 'signup', 'signup/verify', 'forgot-password',
        'forgot-password/verify', 'forgot-password/reset-password', 'exception'];

    /** Everything below these is a private screen. */
    private const PRIVATE_TREES = ['account', 'checkout', 'admin'];

    public static function classify(string $path): string
    {
        $path     = trim($path, '/');
        $segments = explode('/', $path);

        if (in_array($path, self::INDEXED, true)) {
            return self::INDEX;
        }

        if (count($segments) === 2 && $segments[1] !== '' && in_array($segments[0], self::INDEXED_BY_SLUG, true)) {
            return self::INDEX;
        }

        if (in_array($path, self::PRIVATE, true) || in_array($segments[0], self::PRIVATE_TREES, true)) {
            return self::NOINDEX;
        }

        return self::MISSING;
    }

    /**
     * A request for a file rather than a page: scanners probing for
     * /wp-login.php or /.git, or a stale asset URL. Those get Laravel's plain
     * 404 - rendering the whole shop (several queries) for each probe would
     * hand bots a cheap way to load the database.
     */
    public static function looksLikeFile(string $path): bool
    {
        $path = trim($path, '/');

        if (str_starts_with($path, '.') || str_contains($path, '/.')) {
            return true;
        }

        $last = substr($path, (int) strrpos('/' . $path, '/'));

        return (bool) preg_match('/\.(php\d?|aspx?|jsp|cgi|env|git|sql|bak|old|zip|gz|tar|rar|log|ini|ya?ml|xml|txt|json|js|mjs|css|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf)$/i', $last);
    }
}
