<?php

use App\Enums\CashAccount;
use App\Enums\CashEntryType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOTE = 'Card/MFS till sale, added when e-money joined the cash page';

    /**
     * Till sales paid by card or MFS were left off the cash page until now.
     * Those made since the page went live (its first entry) are written in, at
     * the time of each sale, so the new e-money accounts open on their real
     * figure rather than on zero.
     *
     * Runs while the site is down for the deploy, so no new sale can land in
     * between; and does nothing if those accounts already hold anything, since
     * the running balances written here assume they start empty.
     */
    public function up(): void
    {
        if (!Schema::hasTable('cash_entries')) {
            return;
        }
        if (DB::table('cash_entries')->whereIn('account', [CashAccount::POS_CARD, CashAccount::POS_MFS])->exists()) {
            return;
        }

        $since = DB::table('cash_entries')->min('created_at');
        if (!$since) {
            return;
        }

        $accounts = [
            PosPaymentMethod::CARD           => CashAccount::POS_CARD,
            PosPaymentMethod::MOBILE_BANKING => CashAccount::POS_MFS,
        ];

        $orders = DB::table('orders')
            ->where(fn($query) => $query->where('source', Source::POS)->orWhere('order_type', OrderType::POS))
            ->whereIn('pos_payment_method', array_keys($accounts))
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED])
            ->whereNotNull('outlet_id')
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'outlet_id', 'pos_payment_method', 'total', 'order_serial_no', 'created_at']);

        $balances = [];
        $rows     = [];
        foreach ($orders as $order) {
            $account = $accounts[(int) $order->pos_payment_method];
            $key     = $order->outlet_id . ':' . $account;
            $amount  = round((float) $order->total, 2);

            $balances[$key] = round(($balances[$key] ?? 0) + $amount, 2);

            $rows[] = [
                'outlet_id'     => $order->outlet_id,
                'account'       => $account,
                'type'          => CashEntryType::POS_SALE,
                'amount'        => $amount,
                'balance_after' => $balances[$key],
                'order_id'      => $order->id,
                'reference'     => $order->order_serial_no,
                'note'          => self::NOTE,
                'created_at'    => $order->created_at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('cash_entries')->insert($chunk);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_entries')) {
            DB::table('cash_entries')->where('note', self::NOTE)->delete();
        }
    }
};
