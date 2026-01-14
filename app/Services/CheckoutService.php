<?php

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\Source;
use App\Events\SendOrderMail;
use App\Events\SendOrderSms;
use App\Events\SendOrderPush;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLineItem;
use App\Models\OrderOutletAddress;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function order($request)
    {
        \Log::info('🛍️ [Checkout] Order request received', [
            'total' => $request->total,
            'wallet_discount' => $request->wallet_discount,
            'payment_method' => $request->payment_method,
            'user_id' => Auth::id()
        ]);
        
        $order = DB::transaction(function () use ($request) {
            $authUSer = Auth::user();
            $order    = Order::create([
                'order_serial_no'           => 'ORD-' . Str::random(8),
                'user_id'                   => $authUSer->id,
                'subtotal'                  => $request->subtotal,
                'discount'                  => $request->discount,
                'tax'                       => $request->tax,
                'total'                     => $request->total,
                'total_amount_for_cashback' => $request->total_amount_for_cashback,
                'wallet_discount'           => $request->wallet_discount,
                'shipping_charge'           => $request->shipping_charge,
                'order_type'                => $request->order_type,
                'order_datetime'            => Carbon::now(),
                'payment_method'            => $request->payment_method,
                'payment_status'            => PaymentStatus::UNPAID,
                'status'                    => OrderStatus::PENDING,
                'source'                    => Source::WEB,
            ]);

            \Log::info('🛒 [Checkout] Order Created', [
                'order_id' => $order->id,
                'wallet_discount' => $order->wallet_discount,
                'total' => $order->total,
                'payment_method' => $order->payment_method
            ]);

            if ($request->order_type == OrderType::DELIVERY) {
                OrderAddress::create([
                    'order_id'     => $order->id,
                    'address_type' => AddressType::SHIPPING,
                    'address_id'   => $request->shipping_address['id'],
                ]);

                OrderAddress::create([
                    'order_id'     => $order->id,
                    'address_type' => AddressType::BILLING,
                    'address_id'   => $request->billing_address['id'],
                ]);
            } else {
                OrderOutletAddress::create([
                    'order_id'  => $order->id,
                    'outlet_id' => $request->outlet_address['id'],
                ]);
            }

            foreach ($request->items as $item) {
                $product = Product::find($item['item_id']);
                if ($product) {
                    OrderLineItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $product->id,
                        'price'      => $item['price'],
                        'quantity'   => $item['quantity'],
                        'discount'   => $item['discount'],
                        'tax'        => $item['tax'],
                        'total'      => $item['total'],
                    ]);

                    $stock = Stock::where('product_id', $product->id)->first();
                    if ($stock) {
                        $stock->quantity -= $item['quantity'];
                        $stock->save();
                    }
                }
            }

            // Log wallet discount for debugging
            \Log::info('💰 [Checkout] Wallet Discount', [
                'wallet_discount' => $request->wallet_discount,
                'user_balance_before' => $authUSer->balance,
                'order_id' => $order->id
            ]);

            if ($request->wallet_discount > 0) {
                // Use users.balance instead of wallets table
                $balanceBefore = $authUSer->balance;
                $authUSer->balance -= $request->wallet_discount;
                $authUSer->save();
                
                // Create wallet transaction record
                Transaction::create([
                    'order_id' => $order->id,
                    'transaction_no' => 'TXN-' . time() . '-' . $order->id,
                    'amount' => $request->wallet_discount,
                    'payment_method' => 'wallet',
                    'type' => 'payment',
                    'sign'           => '-',
                    'user_id'        => $authUSer->id,
                    'admin_id'       => null,
                    'note'           => 'Wallet payment for order #' . $order->order_serial_no,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $authUSer->balance,
                ]);
            }

            $admins = User::where('role', Role::ADMIN)->get();
            $users  = [$authUSer];
            $users  = array_merge($users, $admins);

            SendOrderMail::dispatch(['order' => $order, 'users' => $users]);
            SendOrderSms::dispatch(['order' => $order, 'users' => $users]);
            SendOrderPush::dispatch(['order' => $order, 'users' => $users]);

            return $order;
        });
        return $order;
    }
}
