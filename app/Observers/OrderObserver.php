<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletSetting;
use App\Services\TransactionService;

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
        if ($walletSetting && $walletSetting->cashback_status) {
            $user = User::find($order->user_id);
            if ($user) {
                $cashbackAmount = ($order->total_amount_for_cashback * $walletSetting->cashback_percentage) / 100;

                if ($cashbackAmount > 0) {
                    if ($walletSetting->cashback_limit_per_order > 0 && $cashbackAmount > $walletSetting->cashback_limit_per_order) {
                        $cashbackAmount = $walletSetting->cashback_limit_per_order;
                    }

                    // Use users.balance instead of wallets table
                    $balanceBefore = $user->balance;
                    $user->balance += $cashbackAmount;
                    $user->save();

                    $transactionService = new TransactionService();
                    $transactionService->create(
                        $cashbackAmount,
                        $order->order_serial_no,
                        $user->id,
                        TransactionStatus::SUCCESS,
                        TransactionType::CASHBACK,
                        'Cashback for order ' . $order->order_serial_no,
                        $user->balance
                    );
                }
            }
        }
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
