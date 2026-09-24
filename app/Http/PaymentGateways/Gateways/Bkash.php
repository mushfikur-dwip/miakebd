<?php

namespace App\Http\PaymentGateways\Gateways;


use Exception;
use App\Enums\Activity;
use App\Models\Currency;
use App\Enums\GatewayMode;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\PaymentAbstract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Dipokhalder\Settings\Facades\Settings;
use Karim007\LaravelBkashTokenize\Facade\BkashPaymentTokenize;

class Bkash extends PaymentAbstract
{
    public mixed $response;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $paymentService = new PaymentService();
        parent::__construct($paymentService);
        $this->paymentGateway = PaymentGateway::with('gatewayOptions')->where(['slug' => 'bkash'])->first();
        $this->paymentGatewayOption = $this->paymentGateway->gatewayOptions->pluck('value', 'option');
        Config::set('bkash.sandbox', $this->paymentGatewayOption['bkash_mode'] == GatewayMode::SANDBOX ? true : false);
        Config::set('bkash.bkash_app_key', $this->paymentGatewayOption['bkash_app_key']);
        Config::set('bkash.bkash_app_secret', $this->paymentGatewayOption['bkash_app_secret']);
        Config::set('bkash.bkash_username', $this->paymentGatewayOption['bkash_username']);
        Config::set('bkash.bkash_password', $this->paymentGatewayOption['bkash_password']);
    }

    public function payment($order, $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $currencyCode = 'BDT';
            $currencyId   = Settings::group('site')->get('site_default_currency');
            if (!blank($currencyId)) {
                $currency = Currency::find($currencyId);
                if ($currency) {
                    $currencyCode = $currency->code;
                }
            }

            Config::set('bkash.callbackURL', route('payment.success', ['order' => $order, 'paymentGateway' => 'bkash']));

            $request['intent']                = 'sale';
            $request['mode']                  = '0011';
            $request['payerReference']        = $order->order_serial_no;
            $request['currency']              = $currencyCode;
            $request['amount']                = (float)$order->total;
            $request['merchantInvoiceNumber'] = $order->order_serial_no;
            $request['callbackURL'] = route('payment.success', ['order' => $order, 'paymentGateway' => 'bkash']);

            $dataJson = json_encode($request->all());
            $bkash =  BkashPaymentTokenize::cPayment($dataJson);

            if (isset($bkash['bkashURL'])) {
                return redirect()->away($bkash['bkashURL']);
            } else {
                return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                    'error',
                    $bkash['statusMessage']
                );
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                'error',
                $e->getMessage()
            );
        }
    }

    public function status(): bool
    {
        $paymentGateways = PaymentGateway::where(['slug' => 'bkash', 'status' => Activity::ENABLE])->first();
        if ($paymentGateways) {
            return true;
        }
        return false;
    }

    public function success($order, $request): \Illuminate\Http\RedirectResponse
    {
        try {

            if ($request['status'] === "success" && $request['paymentID']) {
                $response = BkashPaymentTokenize::executePayment($request['paymentID']);
                if (!$response) {
                    $response = BkashPaymentTokenize::queryPayment($request['paymentID']);
                }
                // "Completed" alone proved only that SOME payment went through.
                // The paymentID arrives on the callback URL, so a customer could
                // start a cheap order's bKash payment and send its paymentID to
                // an expensive order's success URL instead: the execute ran
                // here and settled the wrong order. The payment must be for
                // this order's amount, and its trxID must not have settled
                // any other order. The invoice is compared too, but only when
                // bKash returns it - execute and query name the field
                // differently, and a paid order must never be refused over a
                // missing key.
                $invoice = $response['merchantInvoiceNumber'] ?? $response['merchantInvoice'] ?? null;
                $trxId   = (string) ($response['trxID'] ?? '');

                if (
                    isset($response['statusCode']) && $response['statusCode'] == "0000"
                    && ($response['transactionStatus'] ?? null) == "Completed"
                    && ($invoice === null || (string) $invoice === (string) $order->order_serial_no)
                    && abs((float) ($response['amount'] ?? 0) - (float) $order->total) < 0.01
                    && $trxId !== ''
                ) {
                    // Serialised per trxID so two callbacks racing with the
                    // same payment cannot both pass the "unused" check.
                    $settled = Cache::lock('bkash-trx-' . $trxId, 30)->block(10, function () use ($order, $trxId) {
                        if (Transaction::where('transaction_no', $trxId)->where('order_id', '!=', $order->id)->exists()) {
                            return false;
                        }

                        $this->paymentService->payment($order, 'bkash', $trxId);

                        return true;
                    });

                    if ($settled) {
                        return redirect()->route('payment.successful', ['order' => $order])->with(
                            'success',
                            trans('all.message.payment_successful')
                        );
                    }

                    Log::warning('bKash trxID already settled another order.', ['order_id' => $order->id, 'trx_id' => $trxId]);

                    return redirect()->route('payment.fail', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                        'error',
                        trans('all.message.something_wrong')
                    );
                }

                if (isset($response['statusCode']) && $response['statusCode'] == "0000") {
                    // bKash says completed but the payment does not match this
                    // order - log it so a genuine mismatch can be reconciled.
                    Log::warning('bKash payment does not match order.', [
                        'order_id'  => $order->id,
                        'order_no'  => $order->order_serial_no,
                        'total'     => $order->total,
                        'amount'    => $response['amount'] ?? null,
                        'invoice'   => $invoice,
                        'trx_id'    => $trxId,
                    ]);
                }

                return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                    'error',
                    $response['statusMessage']
                );
            } else {
                return redirect()->route('payment.fail', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                    'error',
                    $request['status'] ?? trans('all.message.something_wrong')
                );
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            return redirect()->route('payment.fail', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
                'error',
                $e->getMessage()
            );
        }
    }

    public function fail($order, $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'bkash'])->with(
            'error',
            trans('all.message.something_wrong')
        );
    }

    public function cancel($order, $request): \Illuminate\Http\RedirectResponse
    {
        return redirect('/checkout/payment');
    }
}