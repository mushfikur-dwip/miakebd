<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The shopper's own address, for ad platforms' matching (Meta, TikTok).
 *
 * The site sits behind Hostinger's CDN and TrustProxies trusts no proxy, so
 * $request->ip() may be the CDN edge the request came through - the same
 * address for thousands of shoppers, which an ad platform can match to nobody.
 * The CDN names the visitor first in X-Forwarded-For. Trusting that header
 * app-wide would let anyone dodge the per-IP rate limits by writing it
 * themselves; here the worst a forged value can do is spoil the match of the
 * forger's own event, so it is read for this one purpose only.
 */
class ClientIp
{
    public static function of(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }

        foreach (explode(',', (string) $request->headers->get('X-Forwarded-For', '')) as $candidate) {
            $candidate = trim($candidate);

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $candidate;
            }
        }

        return $request->ip();
    }
}
