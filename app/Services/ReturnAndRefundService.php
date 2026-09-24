<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\ReturnAndRefund;
use App\Enums\ReturnOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PaginateRequest;
use App\Models\ReturnAndRefundProduct;
use Dipokhalder\Settings\Facades\Settings;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\ReturnAndRefundRequest;
use App\Libraries\QueryExceptionLibrary;

class ReturnAndRefundService
{
    public object $returnAndRefund;
    protected $returnFilter = [
        'order_serial_no',
        'user_id',
        'status',
    ];

    protected $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request, $auth = false)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return ReturnAndRefund::where(function ($query) use ($requests, $auth) {
                if ($auth) {
                    $query->where('user_id', auth()->user()->id);
                }
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = Date('Y-m-d', strtotime($requests['from_date']));
                    $last_date  = Date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('created_at', '>=', $first_date)->whereDate(
                        'created_at',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->returnFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(ReturnAndRefundRequest $request): object
    {
        try {
            // Only the caller's own delivered order. The id used to be taken on
            // trust, so anyone could file a return against a stranger's order -
            // which also used up that order's one return slot.
            $order = Order::where('id', $request->order_id)->where('user_id', Auth::id())->first();
            if (!$order || (int) $order->status !== OrderStatus::DELIVERED) {
                throw new Exception(trans('all.message.return_not_allowed'), 422);
            }

            $returnAndRefund = ReturnAndRefund::where('order_id', $order->id)->first();
            if ($returnAndRefund) {
                throw new Exception(trans('all.message.return_request_exist'), 422);
            } else {
                $lines = $this->returnLines($order, json_decode((string) $request->products, true));

                DB::transaction(function () use ($request, $order, $lines) {
                    $this->returnAndRefund = ReturnAndRefund::create([
                        'return_reason_id' => $request->return_reason_id,
                        'note'             => $request->note ? $request->note : "",
                        'order_id'         => $order->id,
                        'user_id'          => Auth::user()->id,
                        'order_serial_no'  => $order->order_serial_no,
                        'status'           => ReturnOrderStatus::PENDING
                    ]);

                    foreach ($lines as $line) {
                        ReturnAndRefundProduct::create($line + [
                            'return_and_refund_id' => $this->returnAndRefund->id,
                            'user_id'              => Auth::user()->id,
                        ]);
                    }
                    if ($request->image) {
                        foreach ($request->image as $image) {
                            $this->returnAndRefund->addMedia($image)->toMediaCollection('return');
                        }
                    }
                });
                return $this->returnAndRefund;
            }
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Prices each requested return line from the order's own stock rows.
     *
     * The browser used to send price, total, tax, order_quantity and
     * return_price for every line, and they were stored as sent - the refund
     * was whatever the customer typed. Only product, variation and quantity
     * are read from the request now; the formula is the one the old code
     * applied, fed with the stored values.
     *
     * @throws Exception
     */
    private function returnLines(Order $order, $requested): array
    {
        if (!is_array($requested) || blank($requested)) {
            throw new Exception(trans('all.message.return_invalid_products'), 422);
        }

        $stocks        = Stock::where(['model_type' => Order::class, 'model_id' => $order->id])->get();
        $orderDiscount = (float) $order->subtotal > 0 ? (float) $order->discount / (float) $order->subtotal : 0;
        $lines         = [];
        $seen          = [];

        foreach ($requested as $item) {
            $productId   = (int) ($item['product_id'] ?? 0);
            $variationId = !empty($item['has_variation']) ? (int) ($item['variation_id'] ?? 0) : 0;

            $stock = $stocks->first(function ($stock) use ($productId, $variationId) {
                if ((int) $stock->product_id !== $productId) {
                    return false;
                }

                return $variationId > 0
                    ? $stock->item_type === ProductVariation::class && (int) $stock->item_id === $variationId
                    : $stock->item_type === Product::class;
            });

            $orderedQuantity = $stock ? abs((float) $stock->quantity) : 0;
            $quantity        = (int) ($item['quantity'] ?? 0);

            if (!$stock || isset($seen[$stock->id]) || $quantity < 1 || $quantity > $orderedQuantity) {
                throw new Exception(trans('all.message.return_invalid_products'), 422);
            }
            $seen[$stock->id] = true;

            $unitPrice  = (float) $stock->price;
            $taxPerUnit = (float) $stock->tax / $orderedQuantity;
            $base       = $unitPrice * $quantity;

            $lines[] = [
                'product_id'      => $productId,
                'variation_id'    => $variationId > 0 ? $variationId : null,
                'variation_names' => $variationId > 0 ? $stock->variation_names : null,
                'quantity'        => $quantity,
                'price'           => $unitPrice,
                'total'           => abs((float) $stock->total),
                'return_price'    => ($base - ($orderDiscount * $base)) + ($taxPerUnit * $quantity),
            ];
        }

        return $lines;
    }

    /**
     * @throws Exception
     */
    public function show(ReturnAndRefund $returnAndRefund, $auth = false): ReturnAndRefund|array
    {
        try {
            if ($auth) {
                if ($returnAndRefund->user_id == Auth::user()->id) {
                    return $returnAndRefund;
                } else {
                    return [];
                }
            } else {
                return $returnAndRefund;
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(ReturnAndRefund $returnAndRefund, OrderStatusRequest $request): ReturnAndRefund
    {
        try {

            if ($request->status == ReturnOrderStatus::REJECTED) {
                $request->validate([
                    'reason' => 'required|max:700',
                ]);

                if ($request->reason) {
                    $returnAndRefund->reject_reason = $request->reason;
                }
            }

            $order = Order::findOrFail($returnAndRefund->order_id);

            DB::transaction(function () use ($returnAndRefund, $request, $order) {
                // Credit only when the return is ACCEPTED, and only once. It used
                // to run on every status change - rejecting a return paid it out
                // too, and each further change paid it again. The amount now
                // comes from return lines priced by returnLines() from the
                // order's own stock rows, never from the browser.
                //
                // The setting comparison is left exactly as it was: the settings
                // store returns strings, so this `=== Ask::YES` has not been
                // matching. Turning wallet refunds on is a business decision,
                // not something a security fix should do quietly.
                if (
                    (int) $request->status === ReturnOrderStatus::ACCEPT
                    && (int) $returnAndRefund->status !== ReturnOrderStatus::ACCEPT
                    && $order->payment_method !== 1
                    && Settings::group('site')->get('site_is_return_product_price_add_to_credit') === Ask::YES
                    && !Transaction::where(['order_id' => $order->id, 'type' => 'return_refund'])->exists()
                ) {
                    $user   = User::where('id', $returnAndRefund->user_id)->lockForUpdate()->firstOrFail();
                    $amount = (float) $returnAndRefund->returnProducts()->sum('return_price');

                    if ($amount > 0) {
                        $balanceBefore = (float) $user->balance;
                        $user->balance = $balanceBefore + $amount;
                        $user->save();

                        Transaction::create([
                            'order_id'       => $order->id,
                            'transaction_no' => 'RF-' . $returnAndRefund->id . '-' . time(),
                            'amount'         => $amount,
                            'payment_method' => 'wallet',
                            'type'           => 'return_refund',
                            'sign'           => '+',
                            'user_id'        => $user->id,
                            'admin_id'       => Auth::id(),
                            'note'           => 'Return refund for order #' . $order->order_serial_no,
                            'balance_before' => $balanceBefore,
                            'balance_after'  => $user->balance,
                        ]);
                    }
                }

                $returnAndRefund->status = $request->status;
                $returnAndRefund->save();
            });

            return $returnAndRefund;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
