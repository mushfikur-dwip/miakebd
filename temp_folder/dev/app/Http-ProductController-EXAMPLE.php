<?php
/**
 * suglow.com — how to wire SeoSchema into your product controller
 *
 * THIS IS AN EXAMPLE, NOT A DROP-IN FILE.
 * Open your real controller (likely dev/app/Http/Controllers/ProductController.php)
 * and add the two marked lines to your existing show() method.
 *
 * BUG 2 FIX: structured data is shared to the Blade root view here, because Vue
 * templates cannot contain <script> tags. Rendering it server-side is also what
 * makes it visible to crawlers that do not run JavaScript.
 */

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\SeoSchema;                 // <-- ADD THIS IMPORT
use Illuminate\Support\Facades\View;       // <-- ADD THIS IMPORT
use Inertia\Inertia;

class ProductController extends Controller
{
    public function show(string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        // ===== ADD THIS ONE LINE =========================================
        // Makes $structuredData available to app.blade.php for this request.
        View::share('structuredData', SeoSchema::product($product));
        // =================================================================

        return Inertia::render('Product/Show', [
            'product' => $product,
        ]);
    }

    /** Homepage — share the Organization + WebSite schema instead. */
    public function home()
    {
        View::share('structuredData', SeoSchema::organisation());

        return Inertia::render('Home', [
            // ... your existing props
        ]);
    }
}
