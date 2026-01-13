<?php

namespace App\Http\Controllers\Api;

use App\Enums\Activity;
use App\Http\Requests\CheckoutRequest;
use App\Services\CheckoutService;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use App\Services\PaymentManagerService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    public function list(Request $request)
    {
        try {
            return PaymentGatewayResource::collection(PaymentGateway::where(['status' => Activity::ENABLE])->get());
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function order(CheckoutRequest $request)
    {
        try {
            $order = $this->checkoutService->order($request);
            return new OrderResource($order);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function payment($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            $paymentManagerService->payment($order, $paymentGateway, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function success($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->success($order, $paymentGateway, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function fail($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->fail($order, $paymentGateway, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function cancel($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->cancel($order, $paymentGateway, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
