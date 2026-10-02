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
        // Retries the host's momentary "[2002] Operation not permitted"
        // refusals instead of showing them to shoppers. See the class.
        $this->app->bind('db.connector.mysql', \App\Database\RetryingMySqlConnector::class);

        // Every .env write refuses values that would break or extend the file.
        $this->app->bind(\Dipokhalder\EnvEditor\EnvEditor::class, \App\Support\SafeEnvEditor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Every export: customer-typed text starting with "=" is written as
        // text, never as a live formula. See the class.
        config(['excel.value_binder.default' => \App\Support\SafeExcelValueBinder::class]);

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
            // PublicStorageFile does the work - path confinement, a media-only
            // allow-list and a sandbox header - shared with routes/web.php.
            Route::get('/storage/{path}', function (string $path) {
                return \App\Support\PublicStorageFile::respond($path);
            })->where('path', '.*');
        });
    }
}
