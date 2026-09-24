<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmsCampaignRequest;
use App\Http\Requests\SmsCampaignTestRequest;
use App\Http\Resources\SmsCampaignResource;
use App\Models\SmsCampaign;
use App\Services\SmsCampaignService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SmsCampaignController extends Controller implements HasMiddleware
{
    public function __construct(private SmsCampaignService $smsCampaignService)
    {
    }

    /**
     * Guarded by the existing `customers` permission rather than a new one:
     * this page messages the customer list, so whoever may see that list may
     * message it, and there is no second permission to keep in sync.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:customers'),
        ];
    }

    /** Recent campaigns, newest first. */
    public function index()
    {
        try {
            return SmsCampaignResource::collection(
                SmsCampaign::orderBy('id', 'desc')->limit(20)->get()
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** How many people a campaign would reach, shown before anything is sent. */
    public function audience()
    {
        try {
            return response(['status' => true, 'total' => $this->smsCampaignService->audienceSize()]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(SmsCampaign $smsCampaign)
    {
        try {
            return new SmsCampaignResource($smsCampaign);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** Creates the campaign and freezes its recipient list. Sends nothing yet. */
    public function store(SmsCampaignRequest $request)
    {
        try {
            return new SmsCampaignResource(
                $this->smsCampaignService->create($request->message, $request->title)
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** One batch per request; the page calls this in a loop and draws progress. */
    public function batch(SmsCampaign $smsCampaign)
    {
        try {
            return new SmsCampaignResource($this->smsCampaignService->sendBatch($smsCampaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function pause(SmsCampaign $smsCampaign)
    {
        try {
            return new SmsCampaignResource($this->smsCampaignService->pause($smsCampaign));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function test(SmsCampaignTestRequest $request)
    {
        try {
            $this->smsCampaignService->sendTest(
                (string) $request->country_code,
                $request->phone,
                $request->message,
                (string) $request->name
            );

            return response(['status' => true]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
