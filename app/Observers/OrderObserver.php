<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletSetting;

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

        $user = User::find($order->user_id);
        if (!$user) {
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

        // Use users.balance instead of wallets table
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
