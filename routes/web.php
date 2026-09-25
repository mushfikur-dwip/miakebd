<?php

use App\Http\Controllers\Frontend\PaymentController;
use App\Http\Controllers\Frontend\RootController;
use App\Http\Controllers\Installer\InstallerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::get('/requirement', [InstallerController::class, 'requirement'])->name('requirement');
    Route::get('/permission', [InstallerController::class, 'permission'])->name('permission');
    Route::get('/license', [InstallerController::class, 'license'])->name('license');
    Route::post('/license', [InstallerController::class, 'licenseStore'])->name('licenseStore');
    Route::get('/site', [InstallerController::class, 'site'])->name('site');
    Route::post('/site', [InstallerController::class, 'siteStore'])->name('siteStore');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database', [InstallerController::class, 'databaseStore'])->name('databaseStore');
    Route::get('/final', [InstallerController::class, 'final'])->name('final');
    Route::get('/final-store', [InstallerController::class, 'finalStore'])->name('finalStore');
});

Route::get('/', [RootController::class, 'index'])->middleware(['installed'])->name('home');
Route::get('/product/{product:slug}', [RootController::class, 'product'])
    ->middleware(['installed'])
    ->name('product.show');

// "All Product" is the root category, so its listing only ever covered its own
// descendants — 336 of the 440 live products, with Baby Care, Fragrance and
// Moisturizer sitting outside the tree. Send it to the unfiltered listing, which
// has no category filter at all.
//
// A 301 rather than a client-side hop alone: this URL is already indexed, and a
// permanent redirect consolidates its ranking onto /product instead of leaving
// Google with a page that contradicts its own name. Declared before the
// {slug} route so the literal path wins.
Route::get('/product-category/all-product', function (\Illuminate\Http\Request $request) {
    // Carry the query string across, same as the ?category= hop below. Dropping
    // it turned a shared "all products, brand=5, sorted by price" link into a
    // bare listing, and because the hop is a 301 the browser caches that loss.
    return redirect()->route('product.listing', $request->query(), 301);
})->middleware(['installed']);

// Clean category URL. Must be declared before the catch-all fallback so the
// server can render category-specific metadata instead of the SPA shell.
Route::get('/product-category/{slug}', [RootController::class, 'category'])
    ->middleware(['installed'])
    ->where('slug', '[A-Za-z0-9\-_.]+')
    ->name('product.category');

// Old query-string category links keep working, and consolidate their ranking
// signals onto the clean path instead of splitting them.
Route::get('/product', function (\Illuminate\Http\Request $request) {
    $slug = $request->query('category');

    if (is_string($slug) && preg_match('/^[A-Za-z0-9\-_.]+$/', $slug)) {
        // Carry the rest of the query string across. Dropping it turned a
        // bookmarked "sunscreen, brand=5, sorted" listing into a bare category
        // page, and because the hop is a 301 the browser caches that loss.
        $carry = $request->except('category');

        return redirect()->route('product.category', ['slug' => $slug] + $carry, 301);
    }

    return app(RootController::class)->listing();
})->middleware(['installed'])->name('product.listing');

// A brand's own indexable page ("CeraVe price in Bangladesh"). Vue renders the
// same path client-side; this gives crawlers the brand's title, products and
// structured data. Declared before the fallback, like the category route.
Route::get('/brand/{slug}', [RootController::class, 'brand'])
    ->middleware(['installed'])
    ->where('slug', '[A-Za-z0-9\-_.]+')
    ->name('brand.show');

Route::get('/offers', [RootController::class, 'offers'])
    ->middleware(['installed'])
    ->name('offers');
// Blog. Server-rendered metadata for the same reason the product and category
// routes exist: Vue writes the head only after JS runs, and an article that
// serves generic HTML to crawlers cannot rank for what it was written for.
//
// The category route is declared BEFORE /blog/{slug} so "category" is never
// swallowed as a post slug.
Route::prefix('blog')->middleware(['installed'])->group(function () {
    Route::get('/', [RootController::class, 'blogIndex'])->name('blog.index');

    Route::get('/category/{slug}', [RootController::class, 'blogCategory'])
        ->where('slug', '[A-Za-z0-9\-_.]+')
        ->name('blog.category');

    Route::get('/tag/{slug}', [RootController::class, 'blogTag'])
        ->where('slug', '[A-Za-z0-9\-_.]+')
        ->name('blog.tag');

    Route::get('/{slug}', [RootController::class, 'blogPost'])
        ->where('slug', '[A-Za-z0-9\-_.]+')
        ->name('blog.show');
});

Route::prefix('payment')->name('payment.')->middleware(['installed'])->group(function () {
    // Throttled: each of these takes an order id from the URL, and success
    // makes an outbound call to the gateway per request.
    Route::get('/{paymentGateway:slug}/pay/{order}', [PaymentController::class, 'index'])->middleware('throttle:30,1')->name('index');
    Route::post('/{order}/pay', [PaymentController::class, 'payment'])->middleware('throttle:10,1')->name('store');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/success', [PaymentController::class, 'success'])->middleware('throttle:30,1')->name('success');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/fail', [PaymentController::class, 'fail'])->middleware('throttle:30,1')->name('fail');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/cancel', [PaymentController::class, 'cancel'])->middleware('throttle:30,1')->name('cancel');
    // Throttled because the route takes an order id and no credential — the
    // per-order idempotency guard in the controller is the real protection, but
    // this keeps anyone from sweeping the id range at speed.
    Route::get('/successful/{order}', [PaymentController::class, 'successful'])
        ->middleware('throttle:30,1')
        ->name('successful');
});

// /storage normally resolves through the public/storage symlink and never
// reaches PHP. On this host it does not work: LiteSpeed does not follow the
// symlink, so every uploaded image 404'd with an HTML body (proving the request
// had fallen through to Laravel) even though `ls -la` showed the link intact and
// the files were sitting in storage/app/public. The zip-based deploys also keep
// deleting the symlink, and `artisan storage:link` cannot recreate it because
// this host disables both symlink() and exec() in PHP.
//
// Serving the bytes here sidesteps all of that. Declared before the fallback so
// it wins over the SPA shell; when a working symlink IS present the web server
// answers first and this route never runs.
// App\Support\PublicStorageFile does the work - path confinement, a media-only
// allow-list and a sandbox header. AppServiceProvider registers the same route
// again after the framework's storage.local; both call the one implementation.
Route::get('/storage/{path}', function (string $path) {
    return \App\Support\PublicStorageFile::respond($path);
})->where('path', '.*');

Route::fallback(function (\Illuminate\Http\Request $request) {
    // Don't catch API routes
    if ($request->is('api/*')) {
        abort(404);
    }

    return app(RootController::class)->index();
})->middleware(['installed']);
