<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CampaignProductResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\CampaignProductService;
use App\Services\CampaignService;
use Exception;

class CampaignController extends Controller
{
    private CampaignService $campaignService;
    private CampaignProductService $campaignProductService;

    public function __construct(CampaignService $campaignService, CampaignProductService $campaignProductService)
    {
        $this->campaignService        = $campaignService;
        $this->campaignProductService = $campaignProductService;
    }

    /**
     * Campaigns running right now. Feeds the header, footer and mobile nav,
     * and the flash-sale page's lookup for an active flash campaign.
     */
    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        try {
            return CampaignResource::collection($this->campaignService->running($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Campaign $campaign): \Illuminate\Http\Response | CampaignResource
    {
        try {
            $this->abortUnlessRunning($campaign);

            return new CampaignResource($campaign);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    public function products(PaginateRequest $request, Campaign $campaign): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        try {
            $this->abortUnlessRunning($campaign);

            return CampaignProductResource::collection(
                $this->campaignProductService->productWithPagination($request, $campaign)
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    /**
     * The window is enforced on the server, not only by hiding nav links. The
     * slug stays in browser histories and shared messages long after a sale
     * ends, and without this an expired campaign would keep serving its
     * special prices — and keep letting people add them to a cart — to anyone
     * with the old URL.
     *
     * @throws Exception
     */
    private function abortUnlessRunning(Campaign $campaign): void
    {
        if (!$campaign->is_running) {
            throw new Exception(trans('all.message.campaign_not_running'), 404);
        }
    }
}
