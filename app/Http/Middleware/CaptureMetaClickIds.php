<?php

namespace App\Http\Middleware;

use App\Support\MetaPixel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Meta's ad-click id and browser id even when the pixel is blocked.
 *
 * A visitor from a Facebook or Instagram ad lands with `?fbclid=` on the URL.
 * The pixel script normally turns that into the `_fbc` cookie, and every
 * later event carries it - that is how a sale three pages on is credited to
 * the ad. With an ad blocker or iOS protection the script never runs, the
 * click id is lost, and the sale looks organic.
 *
 * So the page itself sets both cookies, in exactly the format the pixel uses
 * (`fb.1.<ms>.<value>`): the pixel adopts cookies it finds rather than making
 * new ones, and the Conversions API reads them from every request. This is
 * what Meta's own Parameter Builder library does server-side.
 *
 * First-party, readable by the page (the pixel must see them) and excluded
 * from Laravel's cookie encryption in bootstrap/app.php for the same reason.
 * Only on HTML page loads, and only when a pixel is configured.
 */
class CaptureMetaClickIds
{
    /** Meta's own lifetime for both cookies. */
    private const LIFETIME_MINUTES = 60 * 24 * 90;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (!$request->isMethod('GET') || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                return $response;
            }

            if (MetaPixel::configuredId() === null) {
                return $response;
            }

            $now = (int) floor(microtime(true) * 1000);

            $fbclid = $request->query('fbclid');
            if (is_string($fbclid) && preg_match('/^[A-Za-z0-9_\-]{8,500}$/', $fbclid)) {
                $current = (string) $request->cookies->get('_fbc', '');

                // A new ad click replaces the old one; a reload of the same
                // landing URL keeps the original click time.
                if (!str_ends_with($current, '.' . $fbclid)) {
                    $response->headers->setCookie($this->cookie($request, '_fbc', "fb.1.{$now}.{$fbclid}"));
                }
            }

            if (blank($request->cookies->get('_fbp'))) {
                $response->headers->setCookie($this->cookie($request, '_fbp', "fb.1.{$now}." . random_int(1000000000, 2147483647)));
            }
        } catch (\Throwable $e) {
            // Tracking must never be able to break a page.
        }

        return $response;
    }

    private function cookie(Request $request, string $name, string $value): Cookie
    {
        return new Cookie(
            $name,
            $value,
            now()->addMinutes(self::LIFETIME_MINUTES),
            '/',
            $this->domain(),
            $request->isSecure() || (bool) config('session.secure'),
            false,  // not HttpOnly: the pixel reads these from the page
            true,   // raw: Meta's format must survive byte for byte
            Cookie::SAMESITE_LAX
        );
    }

    /**
     * The registrable domain, as the pixel itself uses (".suglow.com"), so the
     * cookie the server sets and the one the pixel would set are the same
     * cookie rather than two with the same name. Null on localhost or an IP.
     */
    private function domain(): ?string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return '.' . preg_replace('/^www\./', '', $host);
    }
}
