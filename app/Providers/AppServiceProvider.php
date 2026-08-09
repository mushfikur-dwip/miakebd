<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // /storage normally resolves to the public/storage symlink and never
        // reaches Laravel. The zip-based deploys used here keep deleting that
        // symlink, and once requests fall through to the framework they are
        // swallowed by the framework's own `storage.local` route (registered
        // because the `local` disk has 'serve' => true), which 404s every
        // uploaded image — the admin gallery showed "No Image Available" while
        // the files sat untouched in storage/app/public.
        //
        // This route is the safety net. Registration is wrapped in booted() so
        // it runs after FilesystemServiceProvider's own booted callback —
        // same-URI routes override earlier ones, so this one wins over
        // `storage.local`. When the symlink exists the web server answers
        // first and the route never runs.
        $this->app->booted(function () {
            Route::get('/storage/{path}', function (string $path) {
                $base = realpath(storage_path('app/public'));
                $file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $path);

                // realpath resolves any ../ segments; confine the answer to
                // the public disk root.
                if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
                    abort(404);
                }

                // Media URLs are unique per upload (a replaced image gets a
                // new media id), so a year-long immutable cache is safe and
                // lets the CDN absorb what should have been a static file.
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                ]);
            })->where('path', '.*');
        });
    }
}
