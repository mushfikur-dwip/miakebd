<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Services\OrderService;
use App\Services\CustomerService;
use App\Http\Requests\PosCustomerRequest;
use App\Enums\Status;
use App\Models\User;
use App\Http\Resources\SimpleUserResource;
use App\Http\Requests\PosOrderRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderDetailsResource;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;


class PosController extends AdminController implements HasMiddleware
{
    private OrderService $orderService;
    private CustomerService $customerService;

    public function __construct(OrderService $order,CustomerService $customerService)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->customerService = $customerService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:pos', only: ['store', 'storeCustomer', 'employees']),
        ];
    }

    public function store(PosOrderRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->posOrderStore($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
    /**
     * The "Sale By" picker: active employees, by name. Its own endpoint because
     * the Employees page needs the employees permission, which a cashier
     * usually does not have.
     */
    public function employees(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return SimpleUserResource::collection(
            User::employees()->where('status', Status::ACTIVE)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function storeCustomer(PosCustomerRequest $request
    ): \Illuminate\Http\Response|CustomerResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $customer = $this->customerService->storePosCustomer($request);
            return new CustomerResource($customer);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}