<?php

namespace App\Http\Controllers\Api;

use App\Enums\Activity;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

// The order() method and CheckoutService are gone, not just unrouted. The
// service took subtotal/tax/total and wallet_discount straight off the request
// with no verification and mutated stocks rows in place, which would now also
// corrupt branch quantities. Its route was removed earlier; keeping the code
// meant one re-added route away from all of that coming back. The live order
// path is POST /api/frontend/order.
//
// payment()/success()/fail()/cancel() went the same way: they passed the raw
// URL string to the gateways as the order, so they could never settle anything,
// and a gateway name from the URL picked the class. Payments run through the
// web routes under /payment (Frontend\PaymentController).
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
}
