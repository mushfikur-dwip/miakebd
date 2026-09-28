<?php

use App\Enums\CashEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A count used to post its difference as a ledger row, so counting
     * "moved" money: a miscount changed the drawer's balance. At the owner's
     * request a count now only checks the calculation. The rows those earlier
     * counts posted - and any reversal of them - are taken out, and every
     * affected account's running balance is rebuilt in posting order, so each
     * day's opening and closing read as if the counts had never touched them.
     *
     * The counts themselves stay on record (cash_counts), notes and all; only
     * their link to the removed row is cleared. Safe to run again: with no
     * such rows left it does nothing.
     */
    public function up(): void
    {
        if (!Schema::hasTable('cash_entries')) {
            return;
        }

        $adjustments = DB::table('cash_entries')
            ->where('type', CashEntryType::COUNT_VARIANCE)
            ->get(['id', 'outlet_id', 'account']);
        if ($adjustments->isEmpty()) {
            return;
        }

        $reversals = DB::table('cash_entries')
            ->where('type', CashEntryType::REVERSAL)
            ->whereIn('reverses_id', $adjustments->pluck('id'))
            ->get(['id', 'outlet_id', 'account']);

        $removed  = $adjustments->concat($reversals);
        $accounts = $removed->map(fn($row) => [(int) $row->outlet_id, (int) $row->account])->unique()->values();

        DB::transaction(function () use ($adjustments, $removed, $accounts) {
            if (Schema::hasTable('cash_counts')) {
                DB::table('cash_counts')->whereIn('entry_id', $adjustments->pluck('id'))->update(['entry_id' => null]);
            }

            foreach ($removed->pluck('id')->chunk(500) as $ids) {
                DB::table('cash_entries')->whereIn('id', $ids)->delete();
            }

            foreach ($accounts as [$outletId, $account]) {
                $running = 0.0;
                $rows    = DB::table('cash_entries')
                    ->where('outlet_id', $outletId)
                    ->where('account', $account)
                    ->orderBy('id')
                    ->get(['id', 'amount', 'balance_after']);

                foreach ($rows as $row) {
                    $running = round($running + (float) $row->amount, 2);
                    if (abs((float) $row->balance_after - $running) >= 0.005) {
                        DB::table('cash_entries')->where('id', $row->id)->update(['balance_after' => $running]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // The removed adjustments are not put back: a count is a check now.
    }
};
