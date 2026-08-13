<?php

namespace App\Http\Controllers\Frontend;


use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Http\Requests\PaymentRequest;
use App\Libraries\AppLibrary;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Stock;
use App\Models\ThemeSetting;
use App\Services\PaymentManagerService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private PaymentManagerService $paymentManagerService;

    public function __construct(PaymentManagerService $paymentManagerService)
    {
        $this->paymentManagerService = $paymentManagerService;
    }

    public function index(PaymentGateway $paymentGateway, Order $order): \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse
    {
        $credit          = false;
        $cashOnDelivery  = false;
        $paymentGateways = PaymentGateway::with('gatewayOptions')->where(['status' => Activity::ENABLE])->get();
        $company         = Settings::group('company')->all();
        $site            = Settings::group('site')->all();
        $logo            = ThemeSetting::where(['key' => 'theme_logo'])->first();
        $faviconLogo     = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();
        $currency        = Currency::findOrFail(Settings::group('site')->get('site_default_currency'));
        if ($order?->user?->balance >= $order->total) {
            $credit = true;
        }

        if ($site['site_cash_on_delivery'] == Activity::ENABLE) {
            $cashOnDelivery = true;
        }

        if (blank($order->transaction) && $order->payment_status === PaymentStatus::UNPAID) {
            return view('payment', [
                'company'         => $company,
                'logo'            => $logo,
                'currency'        => $currency,
                'faviconLogo'     => $faviconLogo,
                'paymentGateways' => $paymentGateways,
                'order'           => $order,
                'creditAmount'    => AppLibrary::currencyAmountFormat($order->user?->balance),
                'credit'          => $credit,
                'cashOnDelivery'  => $cashOnDelivery,
                'paymentMethod'   => $paymentGateway
            ]);
        }
        return redirect()->route('home')->with('error', trans('all.message.payment_canceled'));
    }

    public function payment(Order $order, PaymentRequest $request)
    {
        if ($this->paymentManagerService->gateway($request->paymentMethod)->status()) {
            $className = 'App\\Http\\PaymentGateways\\PaymentRequests\\' . ucfirst($request->paymentMethod);
            $gateway   = new $className;
            $request->validate($gateway->rules());
            return $this->paymentManagerService->gateway($request->paymentMethod)->payment($order, $request);
        } else {
            return redirect()->route('payment.index', ['paymentGateway' => $request->paymentMethod, 'order' => $order])->with(
                'error',
                trans('all.message.payment_gateway_disable')
            );
        }
    }

    public function success(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        // payment() refuses disabled gateways, but this endpoint never did —
        // so a gateway switched off in admin still had a live URL that could
        // settle orders. Close it.
        if ($paymentGateway->status != Activity::ENABLE) {
            return redirect()->route('payment.fail', ['paymentGateway' => $paymentGateway->slug, 'order' => $order])->with(
                'error',
                trans('all.message.payment_gateway_disable')
            );
        }

        return $this->paymentManagerService->gateway($paymentGateway->slug)->success($order, $request);
    }

    public function fail(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        return $this->paymentManagerService->gateway($paymentGateway->slug)->fail($order, $request);
    }

    public function cancel(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        return $this->paymentManagerService->gateway($paymentGateway->slug)->cancel($order, $request);
    }

    public function successful(Order $order): \Illuminate\Foundation\Application|\Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        // Reached by an ordinary browser navigation: every payment gateway
        // redirects here server-side once it has settled, and the SPA sends the
        // customer here directly when a wallet covers the whole total.
        //
        // There is no session identity to read at this point. The api middleware
        // group is stateless, so signing in only ever hands the SPA a Bearer
        // token — no session cookie is ever written — and a guest-checkout order
        // has no user attached at all. An Auth::check()/user_id comparison here
        // therefore rejected every legitimate customer, turning a placed order
        // into a 404 with no invoice.
        //
        // Idempotency replaces it, and guards the same thing the ownership check
        // was there for: the side effects below run at most once per order, so
        // walking order ids can no longer fire a stranger's mail/SMS/push. It
        // also fixes a pre-existing bug — refreshing this page used to send a
        // second confirmation email and a second (billable) SMS.
        try {
            $firstVisit = Cache::add('order-confirmed-' . $order->id, true, now()->addDays(30));
        } catch (\Throwable $e) {
            // Never let a cache problem break a completed checkout. Falling open
            // risks a duplicate notification; falling closed loses the invoice.
            $firstVisit = true;
        }

        if ($firstVisit) {
            try {
                // A wallet-covered order never passes through a gateway, so
                // nothing else ever commits it. Every gateway's success() does
                // these three things together; this is the wallet equivalent.
                //
                // active = Ask::YES is the load-bearing one. It defaults to
                // Ask::NO, which is the same integer (10) as Status::INACTIVE,
                // and FrontendOrderService::myOrderStore() deletes every order
                // still sitting at that value when the customer next checks out
                // — taking its stock, addresses and coupon rows with it. A paid
                // wallet order was being destroyed on the customer's next
                // order, with the balance already debited and the Transaction
                // row left orphaned.
                if ($order->wallet_discount > 0 && $order->wallet_discount >= $order->total && $order->payment_status === PaymentStatus::UNPAID) {
                    DB::transaction(function () use ($order) {
                        $order->payment_status = PaymentStatus::PAID;
                        $order->active         = Ask::YES;
                        $order->save();

                        // Same commit the gateways perform: until this runs the
                        // stock is still provisional and is not deducted.
                        Stock::where([
                            'model_id'   => $order->id,
                            'model_type' => Order::class,
                            'status'     => Status::INACTIVE,
                        ])->update(['status' => Status::ACTIVE]);
                    });
                }

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => OrderStatus::PENDING]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => OrderStatus::PENDING]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => OrderStatus::PENDING]);

                SendOrderGotMail::dispatch(['order_id' => $order->id]);
                SendOrderGotSms::dispatch(['order_id' => $order->id]);
                SendOrderGotPush::dispatch(['order_id' => $order->id]);
            } catch (\Exception $e) {
                // Release the idempotency token. Claiming it up front is what
                // stops id-walking, but holding it through a failure would
                // leave the order permanently unconfirmed with no way to retry
                // — a refresh would find the token already taken and skip the
                // work silently.
                Log::error('Order confirmation failed for #' . $order->id . ': ' . $e->getMessage());

                try {
                    Cache::forget('order-confirmed-' . $order->id);
                } catch (\Throwable $ignored) {
                }
            }
        }

        return redirect('/account/order-details/' . $order->id . '?status=success');
    }
}