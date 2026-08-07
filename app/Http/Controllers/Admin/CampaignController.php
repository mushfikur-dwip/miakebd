<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\CampaignRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\CampaignService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CampaignController extends AdminController implements HasMiddleware
{
    private CampaignService $campaignService;

    public function __construct(CampaignService $campaignService)
    {
        parent::__construct();
        $this->campaignService = $campaignService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:campaigns', only: ['index']),
            new Middleware('permission:campaigns_create', only: ['store']),
            new Middleware('permission:campaigns_edit', only: ['update']),
            new Middleware('permission:campaigns_delete', only: ['destroy']),
            new Middleware('permission:campaigns_show', only: ['show']),
        ];
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        try {
            return CampaignResource::collection($this->campaignService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(CampaignRequest $request): \Illuminate\Http\Response | CampaignResource
    {
        try {
            return new CampaignResource($this->campaignService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Campaign $campaign): \Illuminate\Http\Response | CampaignResource
    {
        try {
            return new CampaignResource($this->campaignService->show($campaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(CampaignRequest $request, Campaign $campaign): \Illuminate\Http\Response | CampaignResource
    {
        try {
            return new CampaignResource($this->campaignService->update($request, $campaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Campaign $campaign): \Illuminate\Http\Response
    {
        try {
            $this->campaignService->destroy($campaign);

            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
