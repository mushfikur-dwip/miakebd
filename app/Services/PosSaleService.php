<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Events\SendPosSaleChangeTelegram;
use App\Models\Order;
use App\Models\PosSaleChange;
use App\Support\CashException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only two ways a till sale is put right once it is rung up.
 *
 * Reverse takes the whole sale back everywhere and keeps it on record: the
 * order is cancelled, which takes its money out of the drawer (or off the
 * day's card / MFS sales), puts its units back on the branch's shelf and
 * drops it from every sales figure. It needs the branch PIN - taking money
 * out of a branch always does - and it is final.
 *
 * Change payment method moves the sale's money to where it really went, with
 * the shop's payment-method PIN.
 *
 * Both write a PosSaleChange in the same transaction and tell the shop chat.
 * Deleting, cancelling or marking a till sale unpaid any other way is refused
 * (see OrderService), so these are the only doors and both are watched.
 */
class PosSaleService
{
    public const METHODS = [
        PosPaymentMethod::CASH,
        PosPaymentMethod::CARD,
        PosPaymentMethod::MOBILE_BANKING,
        PosPaymentMethod::OTHER,
    ];

    public function __construct(
        private readonly CashPinService $pins,
        private readonly CashLedgerService $ledger
    ) {
    }

    /**
     * @throws CashException
     */
    public function reverse(Order $order, string $reason, ?string $pin): Order
    {
        $this->assertLive($order);
        $this->pins->verify((int) $order->outlet_id, $pin, 'pos_reverse');

        $change = DB::transaction(function () use ($order, $reason) {
            $sale = $this->lock($order);

            $sale->status = OrderStatus::CANCELED;
            $sale->reason = $reason;
            $sale->save();
            $this->followInLedger($sale);

            return $this->record($sale, PosSaleChange::REVERSE, $reason, [
                'from_method' => (int) $sale->pos_payment_method,
                'from_note'   => $sale->pos_payment_note,
            ]);
        });

        $this->announce($change);

        return $order->refresh();
    }

    /**
     * @throws CashException
     */
    public function changeMethod(Order $order, int $method, ?string $paymentNote, string $reason, ?string $pin): Order
    {
        $this->assertLive($order);
        if (!in_array($method, self::METHODS, true)) {
            throw new CashException(trans('all.message.pos_method_invalid'));
        }
        if ($method === (int) $order->pos_payment_method) {
            throw new CashException(trans('all.message.pos_method_same'));
        }
        $this->pins->verifyMethodPin((int) $order->outlet_id, $pin);

        $change = DB::transaction(function () use ($order, $method, $paymentNote, $reason) {
            $sale = $this->lock($order);
            if ($method === (int) $sale->pos_payment_method) {
                throw new CashException(trans('all.message.pos_method_same'));
            }

            $from     = (int) $sale->pos_payment_method;
            $fromNote = $sale->pos_payment_note;

            $sale->pos_payment_method = $method;
            $sale->pos_payment_note   = $method === PosPaymentMethod::CASH ? null : $paymentNote;
            // Paid in cash means the whole total was handed over, no change due.
            $sale->pos_received_amount = $method === PosPaymentMethod::CASH ? $sale->total : null;
            $sale->save();
            $this->followInLedger($sale);

            return $this->record($sale, PosSaleChange::METHOD, $reason, [
                'from_method' => $from,
                'from_note'   => $fromNote,
                'to_method'   => $method,
                'to_note'     => $sale->pos_payment_note,
            ]);
        });

        $this->announce($change);

        return $order->refresh();
    }

    /**
     * Only a till sale that still stands can be put right here.
     *
     * @throws CashException
     */
    private function assertLive(Order $order): void
    {
        if (!$order->isTillOrder()) {
            throw new CashException(trans('all.message.pos_sale_only'));
        }
        if (in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
            throw new CashException(trans('all.message.pos_sale_already_reversed'));
        }
    }

    /**
     * The sale read again under a row lock: two people pressing at the same
     * moment are taken one after the other, and the second finds it changed.
     *
     * @throws CashException
     */
    private function lock(Order $order): Order
    {
        $sale = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertLive($sale);

        return $sale;
    }

    /**
     * The observer moves the money as the order saves, but logs rather than
     * throws when the ledger fails - a sale must never be stopped by the cash
     * page. A correction must: running the same idempotent sync again here
     * posts nothing when the observer managed, and throws (rolling the whole
     * correction back) when it did not.
     */
    private function followInLedger(Order $sale): void
    {
        $this->ledger->syncOrder($sale);
    }

    private function record(Order $sale, string $action, string $reason, array $methods): PosSaleChange
    {
        return PosSaleChange::create($methods + [
            'order_id'   => $sale->id,
            'outlet_id'  => $sale->outlet_id,
            'action'     => $action,
            'amount'     => round((float) $sale->total, 2),
            'reason'     => $reason,
            'created_by' => Auth::id(),
            'ip'         => request()->ip(),
        ]);
    }

    // After the answer has gone: the Telegram API can take seconds.
    private function announce(PosSaleChange $change): void
    {
        $id = $change->id;
        app()->terminating(fn() => SendPosSaleChangeTelegram::dispatch(['change_id' => $id]));
    }
}
