<?php

namespace App\Services;


use Exception;
use Illuminate\Support\Str;
use App\Models\ProductBrand;
use App\Models\SlugRedirect;
use App\Support\BrandMetaResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ProductBrandRequest;

class ProductBrandService
{
    protected $productCateFilter = [
        'name',
        'slug',
        'description',
        'status',
    ];

    protected $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            // The storefront never shows the placeholder brand. Forced here,
            // server side, rather than trusting a query string from the page.
            $query = ProductBrand::query();

            if ($request->boolean('exclude_default')) {
                $query->storefront();
            }

            return $query->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->productCateFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(ProductBrandRequest $request)
    {
        try {
            $productBrand = ProductBrand::create($request->validated() + ['slug' => $this->uniqueSlug($request->name)]);
            if ($request->image) {
                $productBrand->addMediaFromRequest('image')->toMediaCollection('product-brand');
            }
            return $productBrand;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(ProductBrandRequest $request, ProductBrand $productBrand): ProductBrand
    {
        try {
            // The page stays at its address unless the name itself changed.
            // Every save used to rewrite the slug from the name, so a brand
            // stored as "wishcare" but named "Wish Care" moved to /brand/wish-care
            // on its next edit and its old address - the one Google ranked -
            // went dead. A real rename now leaves a 301 behind.
            $slug = $productBrand->slug;
            if (blank($slug) || Str::slug($request->name) !== Str::slug($productBrand->name)) {
                $slug = $this->uniqueSlug($request->name, $productBrand->id);

                if (filled($productBrand->slug) && $productBrand->slug !== $slug) {
                    SlugRedirect::remember(SlugRedirect::PRODUCT_BRAND, $productBrand->slug, $slug);
                }
            }

            $oldSlug = $productBrand->slug;
            $productBrand->update($request->validated() + ['slug' => $slug]);
            if ($request->image) {
                $productBrand->clearMediaCollection('product-brand');
                $productBrand->addMediaFromRequest('image')->toMediaCollection('product-brand');
            }

            // The page is cached for half an hour; an edit should show now.
            BrandMetaResolver::forget($oldSlug);
            BrandMetaResolver::forget($slug);

            return $productBrand;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * The name's slug, or the name's slug with -2, -3... when another brand
     * already has it, so two brands can never answer at the same address.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $n    = 2;

        while (ProductBrand::where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /**
     * @throws Exception
     */
    public function destroy(ProductBrand $productBrand)
    {
        try {
            $checkProduct = $productBrand->products->whereNull('deleted_at');
            if (!blank($checkProduct)) {
                $productBrand->delete();
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $productBrand->delete();
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(ProductBrand $productBrand)
    {
        try {
            return $productBrand;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
