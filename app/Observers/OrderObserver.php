<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletSetting;
use Illuminate\Support\Facades\DB;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        if ($order->isDirty('status') && $order->status == OrderStatus::DELIVERED) {
            $this->handleCashback($order);
        }
    }

    private function handleCashback(Order $order)
    {
        $walletSetting = WalletSetting::first();
        if (!$walletSetting || !$walletSetting->cashback_status) {
            return;
        }

        // wallet_settings.cashback_type is either 'percentage' of the order's
        // cashback-eligible total or a 'fixed' amount per order, capped by
        // max_cashback_amount when a cap is set.
        if ($walletSetting->cashback_type === 'fixed') {
            $cashbackAmount = (float) $walletSetting->cashback_amount;
        } else {
            $cashbackAmount = ((float) $order->total_amount_for_cashback * (float) $walletSetting->cashback_amount) / 100;
        }

        if ($walletSetting->max_cashback_amount !== null
            && (float) $walletSetting->max_cashback_amount > 0
            && $cashbackAmount > (float) $walletSetting->max_cashback_amount) {
            $cashbackAmount = (float) $walletSetting->max_cashback_amount;
        }

        if ($cashbackAmount <= 0) {
            return;
        }

        // Everything below is one transaction. The balance write and the
        // ledger row have to land together or not at all - a credit with no
        // Transaction row is money that appeared from nowhere as far as the
        // customer's wallet history is concerned.
        DB::transaction(function () use ($order, $cashbackAmount) {
            // Nothing stops an order's status being set to delivered, moved
            // off, and set to delivered again - the observer fires on each
            // change, and there is no state machine in changeStatus. Without
            // this check every such round trip paid the cashback afresh.
            // Scoped to this order, so a customer's other orders are unaffected.
            $alreadyPaid = Transaction::where('order_id', $order->id)
                ->where('type', 'cashback')
                ->lockForUpdate()
                ->exists();

            if ($alreadyPaid) {
                return;
            }

            // Locked and re-read rather than trusting the instance fetched
            // above: a cashback landing at the same moment as a wallet
            // redemption would otherwise both compute from the same starting
            // balance and one of the two would vanish.
            $user = User::where('id', $order->user_id)->lockForUpdate()->first();
            if (!$user) {
                return;
            }

            $balanceBefore  = $user->balance;
            $user->balance += $cashbackAmount;
            $user->save();

            Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => 'TXN-' . time() . '-' . $order->id,
                'amount'         => $cashbackAmount,
                'payment_method' => 'wallet',
                'type'           => 'cashback',
                'sign'           => '+',
                'user_id'        => $user->id,
                'note'           => 'Cashback for order ' . $order->order_serial_no,
                'balance_before' => $balanceBefore,
                'balance_after'  => $user->balance,
            ]);
        });
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}
