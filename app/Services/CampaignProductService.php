<?php

namespace App\Services;

use App\Http\Requests\CampaignProductRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Campaign;
use App\Models\CampaignProduct;
use Exception;
use Illuminate\Support\Facades\Log;

class CampaignProductService
{
    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request, Campaign $campaign)
    {
        try {
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return CampaignProduct::with('campaign', 'product')
                ->where('campaign_id', $campaign->id)
                ->orderBy($orderColumn, $orderType)
                ->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(CampaignProductRequest $request, Campaign $campaign): CampaignProduct
    {
        try {
            return CampaignProduct::create($request->validated() + ['campaign_id' => $campaign->id]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CampaignProductRequest $request, Campaign $campaign, CampaignProduct $campaignProduct): CampaignProduct
    {
        try {
            if ($campaign->id != $campaignProduct->campaign_id) {
                throw new Exception(trans('all.product_match'), 422);
            }

            $campaignProduct->update($request->validated());

            return $campaignProduct;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Campaign $campaign, CampaignProduct $campaignProduct): void
    {
        try {
            if ($campaign->id != $campaignProduct->campaign_id) {
                throw new Exception(trans('all.product_match'), 422);
            }

            $campaignProduct->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * The public campaign page listing.
     *
     * Mirrors ProductSectionProductService::productWithPagination, plus the
     * pivot's special_price which `withPivot` on the relation carries through.
     * Product's SoftDeletes scope keeps deleted products off this page even
     * though CampaignProduct::product() pulls them withTrashed for the admin
     * table — a deleted product must not stay purchasable at a campaign price.
     *
     * @throws Exception
     */
    public function productWithPagination(PaginateRequest $paginateRequest, Campaign $campaign)
    {
        try {
            $perPage = $paginateRequest->get('per_page', 32);

            return $campaign->products()
                ->select(
                    'products.id',
                    'products.name',
                    'products.sku',
                    'products.slug',
                    'products.selling_price',
                    'products.variation_price',
                    'products.maximum_purchase_quantity',
                    'products.status'
                )
                ->withReviewRating()
                ->with('media', 'variations', 'taxes')
                ->active('products.status')
                ->paginate($perPage);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
