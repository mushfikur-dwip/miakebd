<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use App\Exports\OrderExport;
use App\Services\OrderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Requests\PosSaleChangeRequest;
use App\Http\Resources\OrderDetailsResource;
use App\Services\PosSaleService;
use App\Support\CashException;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;


class PosOrderController extends AdminController implements HasMiddleware
{
    private OrderService $orderService;

    public function __construct(OrderService $order, private readonly PosSaleService $posSales)
    {
        parent::__construct();
        $this->orderService = $order;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:pos-orders|pos', only: ['show']),
            new Middleware('permission:pos-orders|pos', only: ['index', 'destroy', 'export', 'changeStatus', 'changePaymentStatus']),
            // Anyone on the till may ask; the PIN decides.
            new Middleware('permission:pos-orders|pos', only: ['reverse', 'changeMethod']),
        ];
    }

    /** The whole sale taken back everywhere, with the branch PIN. */
    public function reverse(Order $order, PosSaleChangeRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->posSales->reverse($order, (string) $request->reason, $request->pin));
        } catch (CashException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->status());
        }
    }

    /** The sale's money moved to the method it was really paid by, with the payment-method PIN. */
    public function changeMethod(Order $order, PosSaleChangeRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->posSales->changeMethod(
                $order,
                $request->integer('pos_payment_method'),
                $request->pos_payment_note,
                (string) $request->reason,
                $request->pin
            ));
        } catch (CashException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->status());
        }
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return OrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Order $order): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Order $order): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->orderService->destroy($order);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'Pos-Order.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(Order $order, OrderStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->changeStatus($order, $request, false));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePaymentStatus(Order $order, PaymentStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order,$request, false));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
