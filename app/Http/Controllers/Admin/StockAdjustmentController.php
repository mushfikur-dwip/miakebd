<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\PaginateRequest;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StockAdjustmentController extends AdminController implements HasMiddleware
{
    public StockAdjustmentService $stockAdjustmentService;

    public function __construct(StockAdjustmentService $stockAdjustmentService)
    {
        parent::__construct();
        $this->stockAdjustmentService = $stockAdjustmentService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:stock', only: ['index', 'show', 'store', 'destroy']),
        ];
    }

    public function index(PaginateRequest $request)
    {
        try {
            return StockAdjustmentResource::collection($this->stockAdjustmentService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        try {
            return new StockAdjustmentResource($this->stockAdjustmentService->show($stockAdjustment));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(StockAdjustmentRequest $request)
    {
        try {
            return new StockAdjustmentResource($this->stockAdjustmentService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(StockAdjustment $stockAdjustment)
    {
        try {
            $this->stockAdjustmentService->destroy($stockAdjustment);
            return response('', 204);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
