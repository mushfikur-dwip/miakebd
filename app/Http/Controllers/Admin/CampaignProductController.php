<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\CampaignProductRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CampaignProductAdminResource;
use App\Models\Campaign;
use App\Models\CampaignProduct;
use App\Services\CampaignProductService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CampaignProductController extends AdminController implements HasMiddleware
{
    private CampaignProductService $campaignProductService;

    public function __construct(CampaignProductService $campaignProductService)
    {
        parent::__construct();
        $this->campaignProductService = $campaignProductService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:campaigns_show', only: ['index', 'store', 'update', 'destroy']),
        ];
    }

    public function index(PaginateRequest $request, Campaign $campaign): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        try {
            return CampaignProductAdminResource::collection($this->campaignProductService->list($request, $campaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(CampaignProductRequest $request, Campaign $campaign): \Illuminate\Http\Response | CampaignProductAdminResource
    {
        try {
            return new CampaignProductAdminResource($this->campaignProductService->store($request, $campaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(CampaignProductRequest $request, Campaign $campaign, CampaignProduct $campaignProduct): \Illuminate\Http\Response | CampaignProductAdminResource
    {
        try {
            return new CampaignProductAdminResource($this->campaignProductService->update($request, $campaign, $campaignProduct));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Campaign $campaign, CampaignProduct $campaignProduct): \Illuminate\Http\Response
    {
        try {
            $this->campaignProductService->destroy($campaign, $campaignProduct);

            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
