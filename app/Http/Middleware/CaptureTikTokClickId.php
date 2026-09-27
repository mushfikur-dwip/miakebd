<?php

namespace App\Http\Middleware;

use App\Support\TikTokPixel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps TikTok's ad-click id for the Events API.
 *
 * A visitor from a TikTok ad lands with `?ttclid=` on the URL. The pixel reads
 * it from there - but the pixel loads late, may be blocked, and the app may
 * have moved to another URL by then. The Events API is what credits a sale to
 * the ad in those cases, and it can only send the click id if the server kept
 * it. Only the server reads this cookie, so it is HttpOnly; it is excluded
 * from cookie encryption in bootstrap/app.php so the API routes, which place
 * orders, can read it too.
 */
class CaptureTikTokClickId
{
    public const COOKIE = 'suglow_ttclid';

    /** Shape-checked on the way in and on the way out. */
    public const PATTERN = '/^[A-Za-z0-9_.=\-]{8,500}$/';

    /** How long TikTok attributes a sale to a click. */
    private const LIFETIME_MINUTES = 60 * 24 * 28;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $ttclid = $request->query('ttclid');

            if (
                !is_string($ttclid)
                || !preg_match(self::PATTERN, $ttclid)
                || !$request->isMethod('GET')
                || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')
                || TikTokPixel::configuredId() === null
                || $request->cookies->get(self::COOKIE) === $ttclid
            ) {
                return $response;
            }

            $response->headers->setCookie(new Cookie(
                self::COOKIE,
                $ttclid,
                now()->addMinutes(self::LIFETIME_MINUTES),
                '/',
                null,
                $request->isSecure() || (bool) config('session.secure'),
                true,   // HttpOnly: no script needs it
                true,   // raw: sent to TikTok byte for byte
                Cookie::SAMESITE_LAX
            ));
        } catch (\Throwable $e) {
            // Tracking must never be able to break a page.
        }

        return $response;
    }
}
