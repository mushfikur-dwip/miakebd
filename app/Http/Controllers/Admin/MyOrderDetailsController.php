<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\User;
use App\Models\Order;
use App\Services\OrderService;
use App\Http\Resources\OrderDetailsResource;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The ownership check in OrderService::orderDetails compares the order against
 * the user named in the URL, not against the caller - so it confirms the two
 * path segments agree with each other and nothing more. With no permission
 * middleware, and /api/admin requiring only auth:sanctum, any logged-in
 * customer could walk user/order id pairs and read other people's orders:
 * name, phone, delivery address and everything bought.
 *
 * This is reached from Administrators → order details, guarded in the router
 * by permissionUrl "administrators".
 */
class MyOrderDetailsController extends AdminController implements HasMiddleware
{

    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        parent::__construct();
        $this->orderService = $orderService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:administrators', only: ['orderDetails']),
        ];
    }

    public function orderDetails(User $user, Order $order) : \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->orderDetails($user, $order));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
