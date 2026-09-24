<?php

namespace App\Observers;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletSetting;
use App\Services\MetaConversionsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        $this->reportPurchaseToMeta($order);
    }

    /**
     * The Purchase event, sent from here rather than only from the browser.
     *
     * The browser's copy is lost whenever an ad blocker, iOS or a closed tab
     * gets in the way, and a missing Purchase is a sale the ad never gets
     * credit for. Both copies carry the same event_id - "order-{id}" - so Meta
     * keeps one and discards the duplicate.
     *
     * Deferred until the order's transaction commits: the order row is
     * inserted first and its product lines and delivery address after it, so
     * at the moment of `created` there is nothing yet to report. Still inside
     * the customer's own request, so their IP, browser and ad click id are the
     * ones Meta matches on.
     *
     * An online payment is stored but held until the money arrives - see
     * updated(). Till orders are excluded: nobody clicked an ad to reach the
     * counter, and reporting them would flatter every campaign.
     */
    private function reportPurchaseToMeta(Order $order): void
    {
        if ($this->isTillOrder($order)) {
            return;
        }

        DB::afterCommit(function () use ($order) {
            try {
                $meta = app(MetaConversionsService::class);

                if (!$meta->enabled()) {
                    return;
                }

                $order   = $order->fresh(['orderProducts', 'user']);
                $address = optional($order->address()->where('address_type', AddressType::SHIPPING)->first());

                $meta->queue(
                    'Purchase',
                    'order-' . $order->id,
                    $meta->userData($order->user, request(), [
                        'city'     => $address->city,
                        'state'    => $address->state,
                        'zip_code' => $address->zip_code,
                        'country'  => $address->country,
                    ]),
                    $meta->orderCustomData($order),
                    url('/account/order-details/' . $order->id),
                    $meta->orderIsSale($order)
                );
            } catch (\Throwable $e) {
                // An ad platform must never be able to break an order.
                Log::warning('Could not queue the Meta Purchase event: ' . $e->getMessage());
            }
        });
    }

    private function isTillOrder(Order $order): bool
    {
        return (int) $order->source === Source::POS || (int) $order->order_type === OrderType::POS;
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        if ($order->isDirty('status') && $order->status == OrderStatus::DELIVERED) {
            $this->handleCashback($order);
        }

        // The online payment went through: the Purchase stored when the order
        // was placed can go to Meta now. A no-op for anything already sent.
        if ($order->isDirty('payment_status') && (int) $order->payment_status === PaymentStatus::PAID && !$this->isTillOrder($order)) {
            try {
                app(MetaConversionsService::class)->release('order-' . $order->id);
            } catch (\Throwable $e) {
                Log::warning('Could not release the Meta Purchase event: ' . $e->getMessage());
            }
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
