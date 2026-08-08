<?php

namespace App\Services;

use Exception;
use App\Models\Product;
use App\Models\ProductSeo;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ProductSeoRequest;
use App\Libraries\QueryExceptionLibrary;

class ProductSeoService
{
    /**
     * @throws Exception
     */
    public function list(Product $product)
    {
        try {
            // An unsaved instance rather than null when the product has no SEO
            // row yet. ProductSeoResource::toArray() reads properties off this,
            // and it runs during response rendering — AFTER the controller's
            // try/catch has already returned — so a null here escaped as an
            // unhandled 500 instead of the 422 the catch block intends. 53
            // products currently have no row, and the SEO tab 500'd on all
            // of them.
            //
            // Unsaved is safe: getThumb/getCoverAttribute fall back to the
            // placeholder when the media collection is empty, which it always
            // is for a model that was never persisted.
            return ProductSeo::where('product_id', $product->id)->first()
                ?? new ProductSeo(['product_id' => $product->id]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(ProductSeoRequest $request, Product $product)
    {
        try {
            $productSeo = ProductSeo::where('product_id', $product->id)->first();
            if (!blank($productSeo)) {
                $productSeo->update($request->validated());
            } else {
                $productSeo = ProductSeo::create($request->validated());
            }
            if ($request->image) {
                $productSeo->clearMediaCollection('product-seo');
                $productSeo->addMediaFromRequest('image')->toMediaCollection('product-seo');
            }
            return $productSeo;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
