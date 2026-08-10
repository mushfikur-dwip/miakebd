<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Exports\StockExport;
use App\Services\StockService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\StockResource;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\StockItemUpdateRequest;
use App\Services\StockAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class StockController extends AdminController implements HasMiddleware
{
    public StockService $stockService;
    public StockAdjustmentService $stockAdjustmentService;

    public function __construct(StockService $stockService, StockAdjustmentService $stockAdjustmentService)
    {
        parent::__construct();
        $this->stockService = $stockService;
        $this->stockAdjustmentService = $stockAdjustmentService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:stock', only: ['index']),
            new Middleware('permission:stock', only: ['export']),
            new Middleware('permission:stock', only: ['itemQuantity']),
            new Middleware('permission:stock', only: ['updateItem']),
        ];
    }

    public function index(PaginateRequest $request): \Illuminate\Foundation\Application|\Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return  StockResource::collection($this->stockService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * What one item currently holds at one branch. The stock adjustment screen
     * calls this so a recalculate is typed over the real figure rather than
     * from memory.
     */
    public function itemQuantity(Request $request)
    {
        try {
            return response([
                'data' => [
                    'quantity' => $this->stockService->itemQuantity(
                        $request->get('product_id'),
                        $request->get('variation_id'),
                        $request->get('outlet_id')
                    ),
                ],
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Sets one product's stock across branches - the edit dialog on the stock
     * screen. Recorded as recalculate adjustments, so it shows up in the
     * adjustment history and can be undone from there.
     */
    public function updateItem(StockItemUpdateRequest $request)
    {
        try {
            $this->stockAdjustmentService->updateItemStock($request);

            return response(['status' => true]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Foundation\Application|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new StockExport($this->stockService, $request), 'Stock.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
