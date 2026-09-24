<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Who may open /payment/{gateway}/pay/{order}.
 *
 * That page is a plain browser navigation, so it carries no Bearer token and
 * there is no logged-in user to compare against (see PaymentController). With
 * nothing but the order id in the URL, anyone could walk the ids: each unpaid
 * order - every COD order until delivery - showed its owner's wallet balance,
 * and the form behind it would spend that balance on the order.
 *
 * The SPA now receives a token for the order it just created and puts it on
 * the URL. The first visit with a valid token marks the order in this
 * browser's web session, so the ~75 gateway redirects back to the page
 * (retries, "payment failed" bounces) keep working without each carrying the
 * token.
 */
class PaymentLink
{
    public static function token(int $orderId): string
    {
        return hash_hmac('sha256', 'order-payment|' . $orderId, (string) config('app.key'));
    }

    public static function isValid(int $orderId, $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token($orderId), $token);
    }

    public static function grant(Request $request, int $orderId): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::sessionKey($orderId), true);
        }
    }

    public static function granted(Request $request, int $orderId): bool
    {
        return $request->hasSession() && $request->session()->get(self::sessionKey($orderId)) === true;
    }

    public static function sessionKey(int $orderId): string
    {
        return 'payment_order_' . $orderId;
    }
}
