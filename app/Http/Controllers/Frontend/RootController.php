<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Product;
use App\Models\ThemeSetting;
use App\Support\SeoSchema;

class RootController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell();
    }

    public function product(Product $product): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        abort_unless($product->status === Status::ACTIVE, 404);

        $product = Product::query()
            ->with(['seo.media', 'media', 'category', 'brand', 'variations'])
            ->withSum('stockItems', 'quantity')
            ->withReviewRating()
            ->findOrFail($product->id);

        $description = SeoSchema::plainText($product->seo?->description ?: $product->description ?: $product->name);
        $title = $product->seo?->title ?: $product->name;
        $keywordValues = json_decode((string) $product->seo?->meta_keyword, true);
        $keywords = implode(', ', is_array($keywordValues) ? $keywordValues : []);
        $canonical = route('product.show', ['product' => $product->slug]);
        $image = $product->seo?->cover ?: $product->cover;

        return $this->shell([
            'seo' => compact('title', 'description', 'keywords', 'canonical', 'image')
                + ['type' => 'product', 'robots' => 'index, follow, max-image-preview:large'],
            'structuredData' => SeoSchema::product($product),
        ]);
    }

    private function shell(array $data = []): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $analytics = Analytic::with('analyticSections')->where(['status' => Status::ACTIVE])->get();
        $themeFavicon = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();

        return view('master', $data + [
            'analytics' => $analytics,
            'favicon' => $themeFavicon?->faviconLogo,
        ]);
    }
}
