<?php

namespace App\Providers;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // The limiters below replace flat per-IP limits on the checkout path.
        // An IP is a poor key for shoppers here: mobile carriers put thousands
        // of customers behind one address, and whenever the app sees the CDN's
        // edge rather than the visitor, one IP is every visitor at once - a
        // flat 10 a minute then capped the WHOLE shop at 10 guest checkouts a
        // minute, exactly when an ad is working. Each now limits the thing
        // being protected (a phone number, an order) tightly, and the IP only
        // loosely, as a backstop against a single machine hammering.

        // Guest checkout mints an account and a token per call.
        RateLimiter::for('guest-start', function (Request $request) {
            $phone = ltrim(preg_replace('/[^0-9]/', '', (string) $request->input('phone')), '0');

            return [
                Limit::perMinute(6)->by('guest-start:phone:' . $phone),
                Limit::perMinute(60)->by('guest-start:ip:' . $request->ip()),
            ];
        });

        // Payment pages and gateway callbacks. Each takes an order id, so the
        // tight limit is per order; the IP limit still stops a sweep of the id
        // range at speed.
        RateLimiter::for('payment-page', function (Request $request) {
            return [
                Limit::perMinute(30)->by('payment-page:' . self::orderKey($request)),
                Limit::perMinute(300)->by('payment-page:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('payment-submit', function (Request $request) {
            return [
                Limit::perMinute(10)->by('payment-submit:' . self::orderKey($request)),
                Limit::perMinute(120)->by('payment-submit:ip:' . $request->ip()),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    // Bindings have usually run by the time a throttle does, but not always
    // (a middleware ordering change would hand over the raw id), so accept both.
    private static function orderKey(Request $request): string
    {
        $order = $request->route('order');
        $id    = is_object($order) && method_exists($order, 'getKey') ? $order->getKey() : $order;

        return $request->ip() . '|' . (is_scalar($id) ? (string) $id : '');
    }

    protected function mapWebRoutes()
    {
        if (file_exists(storage_path('installed'))) {

            try {
                $files = scandir(__DIR__ . '/../Http/PaymentGateways/Routes');
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            Route::middleware('web')
                                ->group(__DIR__ . "/../Http/PaymentGateways/Routes/{$file}");
                        }
                    }
                }
            } catch (Exception $e) {
                Log::info($e->getMessage());
            }
        }
    }
}
