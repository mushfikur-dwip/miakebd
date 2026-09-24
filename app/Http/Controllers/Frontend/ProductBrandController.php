<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Exception;
use App\Services\ProductBrandService;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\ProductBrandResource;

class ProductBrandController extends Controller
{
    private ProductBrandService $productBrandService;

    public function __construct(ProductBrandService $productBrandService)
    {
        $this->productBrandService = $productBrandService;
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // Forced on here rather than left to the page to ask for: this is
            // the only brand endpoint a shopper can reach, so the placeholder
            // brand is filtered out server side and cannot leak through a
            // hand-made request or a stale bundle.
            $request->merge(['exclude_default' => true]);

            return ProductBrandResource::collection($this->productBrandService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
