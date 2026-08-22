<?php

namespace App\Http\Controllers\Api;

use App\Enums\Activity;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use App\Services\PaymentManagerService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

// The order() method and CheckoutService are gone, not just unrouted. The
// service took subtotal/tax/total and wallet_discount straight off the request
// with no verification and mutated stocks rows in place, which would now also
// corrupt branch quantities. Its route was removed earlier; keeping the code
// meant one re-added route away from all of that coming back. The live order
// path is POST /api/frontend/order.
class CheckoutController extends Controller
{
    public function list(Request $request)
    {
        try {
            return PaymentGatewayResource::collection(PaymentGateway::where(['status' => Activity::ENABLE])->get());
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function payment($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            $paymentManagerService->gateway($paymentGateway)->payment($order, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function success($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->gateway($paymentGateway)->success($order, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function fail($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->gateway($paymentGateway)->fail($order, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function cancel($order, $paymentGateway, Request $request)
    {
        try {
            $paymentManagerService = new PaymentManagerService();
            return $paymentManagerService->gateway($paymentGateway)->cancel($order, $request);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
