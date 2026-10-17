<?php

namespace App\Services;

use App\Enums\CashAccount;
use App\Enums\CashEntryType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Libraries\AppLibrary;
use App\Models\CashCount;
use App\Models\CashEntry;
use App\Models\CashPinFailure;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosSaleChange;
use App\Models\Stock;
use App\Support\CashException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every taka a branch holds, as an append-only ledger.
 *
 * A balance is never typed in or stored on its own: it is the running total
 * of the branch account's rows, and each row records who moved the money, from
 * where, and what the account held afterwards. Rows are posted with the branch
 * row locked, so two tills posting at once always see each other's money.
 *
 * Money in (sales, adds, MFS agent business) needs only the page permission.
 * Money out of the branch needs the branch PIN - see CashPinService.
 */
class CashLedgerService
{
    // Each service's pair of pots: e-money on the SIM (the recharge balance,
    // for Recharge), and the notes that business brought in.
    public const MFS = [
        'bkash'    => ['sim' => CashAccount::BKASH_SIM, 'cash' => CashAccount::BKASH_CASH],
        'nagad'    => ['sim' => CashAccount::NAGAD_SIM, 'cash' => CashAccount::NAGAD_CASH],
        'recharge' => ['sim' => CashAccount::RECHARGE_SIM, 'cash' => CashAccount::RECHARGE_CASH],
    ];

    // What each service does at the counter. Recharge is its own service with
    // its own balance, not something bKash or Nagad does.
    public const MFS_KINDS = [
        'bkash'    => ['cash_in', 'cash_out'],
        'nagad'    => ['cash_in', 'cash_out'],
        'recharge' => ['recharge'],
    ];

    public const SIM_ACCOUNTS = [CashAccount::BKASH_SIM, CashAccount::NAGAD_SIM, CashAccount::RECHARGE_SIM];

    // Accounts held as notes and coins: counted note by note. Everything else
    // is e-money, counted by typing the balance the phone or statement shows.
    public const NOTE_ACCOUNTS = [CashAccount::DRAWER, CashAccount::BKASH_CASH, CashAccount::NAGAD_CASH, CashAccount::RECHARGE_CASH];

    // Where a till sale's money goes, by how it was paid. "Other" is not here:
    // it names no place the money can be found in.
    public const POS_ACCOUNTS = [
        PosPaymentMethod::CASH           => CashAccount::DRAWER,
        PosPaymentMethod::CARD           => CashAccount::POS_CARD,
        PosPaymentMethod::MOBILE_BANKING => CashAccount::POS_MFS,
    ];

    public const ACCOUNT_NAMES = [
        CashAccount::DRAWER        => 'Cash drawer',
        CashAccount::BKASH_SIM     => 'bKash SIM',
        CashAccount::BKASH_CASH    => 'bKash cash',
        CashAccount::NAGAD_SIM     => 'Nagad SIM',
        CashAccount::NAGAD_CASH    => 'Nagad cash',
        CashAccount::RECHARGE_SIM  => 'Recharge balance',
        CashAccount::RECHARGE_CASH => 'Recharge cash',
        CashAccount::POS_CARD      => 'Card (POS)',
        CashAccount::POS_MFS       => 'MFS payment (POS)',
    ];

    // Bangladeshi notes and coins, as the blind count asks for them.
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    // The entries an owner may reverse. POS rows follow their order - fix the
    // order and the drawer follows - and a reversal is never itself reversed:
    // the right entry is simply posted again. (Counts are no longer entries;
    // see count().)
    public const REVERSIBLE = [
        CashEntryType::ADD,
        CashEntryType::WITHDRAW,
        CashEntryType::TRANSFER_OUT,
        CashEntryType::TRANSFER_IN,
        CashEntryType::MFS_CASH_IN,
        CashEntryType::MFS_CASH_OUT,
        CashEntryType::MFS_RECHARGE,
    ];

    public function __construct(private readonly CashPinService $pins)
    {
    }

    /**
     * The accounts staff can put money in, take it out of or count: the
     * drawer always, plus the agent-service pots while that is on. The till's
     * card and MFS accounts are not among them - that money goes to the bank
     * and the merchant number, never through the branch, so it is shown as
     * the day's and the month's sales (see sales()), not as a balance.
     */
    public function accounts(Outlet $outlet): array
    {
        $accounts = [CashAccount::DRAWER];

        if ($outlet->mfs_enabled) {
            foreach (self::MFS as $pair) {
                $accounts[] = $pair['sim'];
                $accounts[] = $pair['cash'];
            }
        }

        return $accounts;
    }

    public function canSeeBalance(): bool
    {
        return (bool) Auth::user()?->checkPermissionTo('cash-calculation_balance');
    }

    /**
     * What the account held just before $before, or right now. Read from the
     * last row's running balance - every row is posted under the branch lock,
     * so that figure is exactly the sum of the rows up to it.
     */
    public function balance(int $outletId, int $account, ?Carbon $before = null): float
    {
        $query = CashEntry::where('outlet_id', $outletId)->where('account', $account);

        if ($before) {
            $query->where('created_at', '<', $before);
        }

        return round((float) $query->orderByDesc('id')->value('balance_after'), 2);
    }

    // ---------------------------------------------------------------- POS

    /**
     * Brings the branch's money in line with one till order: its total in the
     * account its payment method points at - the drawer for cash, card or MFS
     * e-money otherwise - while it is a paid, live sale, and nothing anywhere
     * else. Posts only the difference from what is already there, so it is
     * safe to call on every save: cancelling, rejecting, marking unpaid or
     * deleting a sale each post one reversal, restoring it posts the sale
     * again, and a corrected payment method moves the money across.
     */
    public function syncOrder(Order $order, bool $deleted = false): void
    {
        if ((int) $order->source !== Source::POS && (int) $order->order_type !== OrderType::POS) {
            return;
        }

        $account = self::POS_ACCOUNTS[(int) $order->pos_payment_method] ?? null;
        $target  = 0.0;
        if (!$deleted
            && $account !== null
            && (int) $order->payment_status === PaymentStatus::PAID
            && !in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
            $target = round((float) $order->total, 2);
        }

        // What this order has already put where, as "outlet:account" => sum.
        $posted = CashEntry::where('order_id', $order->id)
            ->whereIn('account', array_values(self::POS_ACCOUNTS))
            ->groupBy('outlet_id', 'account')
            ->selectRaw('outlet_id, account, SUM(amount) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn($row) => [(int) $row->outlet_id . ':' . (int) $row->account => round((float) $row->total, 2)]);

        // Whatever sits anywhere else is taken back; the order's own branch
        // and payment account get the target.
        $wanted = $posted->map(fn() => 0.0)->all();
        if ($order->outlet_id && $account !== null) {
            $wanted[(int) $order->outlet_id . ':' . $account] = $target;
        }

        $lines = [];
        foreach ($wanted as $key => $amount) {
            [$outletId, $postedAccount] = array_map('intval', explode(':', $key));
            $difference = round($amount - ($posted[$key] ?? 0), 2);
            if (abs($difference) >= 0.01) {
                $lines[$outletId][] = ['account' => $postedAccount, 'amount' => $difference];
            }
        }

        // Money leaving one account and arriving in another in the same save
        // is the sale's own money moving - its payment method was corrected -
        // not a sale taken back and another rung up.
        $differences = array_column(array_merge([], ...array_values($lines)), 'amount');
        $moved       = $differences && min($differences) < 0 && max($differences) > 0;

        foreach ($lines as $outletId => $outletLines) {
            $this->post($outletId, array_map(fn(array $line) => $line + [
                'type' => $line['amount'] > 0
                    ? ($moved ? CashEntryType::POS_METHOD_IN : CashEntryType::POS_SALE)
                    : ($moved ? CashEntryType::POS_METHOD_OUT : CashEntryType::POS_SALE_REVERSAL),
            ], $outletLines), ['order_id' => $order->id, 'reference' => $order->order_serial_no]);
        }
    }

    // ------------------------------------------------------ staff actions

    /**
     * @throws CashException
     */
    public function add(Outlet $outlet, int $account, float $amount, string $note): Collection
    {
        $this->assertActive($outlet, $account);

        return $this->post($outlet->id, [[
            'account' => $account,
            'type'    => CashEntryType::ADD,
            'amount'  => $amount,
        ]], ['note' => $note]);
    }

    /**
     * One agent transaction. bKash / Nagad Cash In sends e-money and takes
     * notes, Cash Out receives e-money and hands notes over; a Recharge spends
     * recharge balance and takes notes. The pair of rows shares a group_ref.
     *
     * The SIM side is never checked: the owner asked that Cash In and Recharge
     * go through whatever the SIM balance shows, which may be out of date. A
     * SIM that goes below zero is then corrected by its next count. The notes
     * are still checked - a Cash Out cannot hand over notes the box does not
     * hold.
     *
     * @throws CashException
     */
    public function mfs(Outlet $outlet, string $provider, string $kind, float $amount, array $details): Collection
    {
        if (!in_array($kind, self::MFS_KINDS[$provider] ?? [], true)) {
            throw new CashException(trans('all.message.cash_kind_not_allowed'));
        }

        $sim  = self::MFS[$provider]['sim'];
        $cash = self::MFS[$provider]['cash'];
        $this->assertActive($outlet, $sim);

        $type = [
            'cash_in'  => CashEntryType::MFS_CASH_IN,
            'cash_out' => CashEntryType::MFS_CASH_OUT,
            'recharge' => CashEntryType::MFS_RECHARGE,
        ][$kind];
        $simSign = $kind === 'cash_out' ? 1 : -1;

        return $this->post($outlet->id, [
            ['account' => $sim, 'type' => $type, 'amount' => $simSign * $amount],
            ['account' => $cash, 'type' => $type, 'amount' => -$simSign * $amount, 'guard' => true],
        ], $details);
    }

    /**
     * A count checks the calculation; it is never an entry. What was counted
     * - with how many of each note - is kept on record against what the
     * account should hold, in the counter's name, and the balance is left
     * exactly as it was. A mismatch means count again, not that the money
     * moved.
     *
     * @throws CashException
     */
    public function count(Outlet $outlet, int $account, float $counted, ?array $denominations, ?int $countedBy = null): CashCount
    {
        $this->assertActive($outlet, $account);

        // The balance is read under the branch lock, so a sale landing at the
        // same moment cannot leave the count compared with half a figure.
        return DB::transaction(function () use ($outlet, $account, $counted, $denominations, $countedBy) {
            Outlet::whereKey($outlet->id)->lockForUpdate()->first();

            // Every note is kept in the one drawer - the shop's and the bKash,
            // Nagad and Recharge cash alike - so a drawer count is checked
            // against all of them together.
            $expected = $account === CashAccount::DRAWER
                ? round(array_sum(array_map(fn(int $pot) => $this->balance($outlet->id, $pot), self::NOTE_ACCOUNTS)), 2)
                : $this->balance($outlet->id, $account);

            return CashCount::create([
                'outlet_id'     => $outlet->id,
                'account'       => $account,
                'expected'      => $expected,
                'counted'       => $counted,
                'variance'      => round($counted - $expected, 2),
                'denominations' => $denominations,
                'counted_by_id' => $countedBy,
                'created_by'    => Auth::id(),
            ]);
        });
    }

    // ------------------------------------------------- PIN-guarded actions

    /**
     * @throws CashException
     */
    public function withdraw(Outlet $outlet, int $account, float $amount, string $party, string $note, ?string $pin): Collection
    {
        $this->assertActive($outlet, $account);
        $this->pins->verify($outlet->id, $pin, 'withdraw');

        return $this->post($outlet->id, [[
            'account' => $account,
            'type'    => CashEntryType::WITHDRAW,
            'amount'  => -$amount,
            'guard'   => true,
        ]], ['party' => $party, 'note' => $note]);
    }

    /**
     * @throws CashException
     */
    public function transfer(Outlet $outlet, int $from, int $to, float $amount, string $note, ?string $pin): Collection
    {
        if ($from === $to) {
            throw new CashException(trans('all.message.cash_same_account'));
        }
        $this->assertActive($outlet, $from);
        $this->assertActive($outlet, $to);
        $this->pins->verify($outlet->id, $pin, 'transfer');

        return $this->post($outlet->id, [
            ['account' => $from, 'type' => CashEntryType::TRANSFER_OUT, 'amount' => -$amount, 'guard' => true],
            ['account' => $to, 'type' => CashEntryType::TRANSFER_IN, 'amount' => $amount],
        ], ['note' => $note]);
    }

    /**
     * Undoes one manual entry - both legs of it when it was a transfer or an
     * MFS transaction - with a reversal row pointing at each original.
     *
     * @throws CashException
     */
    public function reverse(CashEntry $entry, string $note, ?string $pin): Collection
    {
        if (!in_array($entry->type, self::REVERSIBLE, true)) {
            throw new CashException(trans('all.message.cash_cannot_reverse'));
        }

        $legs = $entry->group_ref
            ? CashEntry::where('group_ref', $entry->group_ref)->orderBy('id')->get()
            : collect([$entry]);

        if (CashEntry::whereIn('reverses_id', $legs->pluck('id'))->exists()) {
            throw new CashException(trans('all.message.cash_already_reversed'));
        }

        $outlet = Outlet::findOrFail($entry->outlet_id);
        foreach ($legs as $leg) {
            $this->assertActive($outlet, $leg->account);
        }

        $this->pins->verify($entry->outlet_id, $pin, 'reverse');

        return DB::transaction(function () use ($entry, $legs, $note) {
            Outlet::whereKey($entry->outlet_id)->lockForUpdate()->first();

            // Again under the lock: two people pressing Reverse at the same
            // moment must not both get through.
            if (CashEntry::whereIn('reverses_id', $legs->pluck('id'))->exists()) {
                throw new CashException(trans('all.message.cash_already_reversed'));
            }

            return $this->post($entry->outlet_id, $legs->map(fn(CashEntry $leg) => [
                'account' => $leg->account,
                'type'    => CashEntryType::REVERSAL,
                'amount'  => -$leg->amount,
                'details' => ['reverses_id' => $leg->id],
            ])->all(), ['note' => $note]);
        });
    }

    /**
     * Turns bKash / Nagad agent service on or off for a branch. Refused while
     * any MFS pot still holds money, so switching it off can never hide cash.
     *
     * @throws CashException
     */
    public function setMfs(Outlet $outlet, bool $enabled, ?string $pin): Outlet
    {
        $this->pins->verify($outlet->id, $pin, 'mfs_toggle');

        if (!$enabled) {
            foreach (self::MFS as $pair) {
                foreach ($pair as $account) {
                    if (abs($this->balance($outlet->id, $account)) >= 0.01) {
                        throw new CashException(trans('all.message.cash_mfs_not_empty'));
                    }
                }
            }
        }

        $outlet->mfs_enabled = $enabled;
        $outlet->save();

        return $outlet;
    }

    /**
     * Settings -> Reset: every account of the branch back to zero, with its
     * PIN. Each account that holds anything gets a "Reset to zero" row for
     * exactly its balance, all posted together under the branch lock - so
     * nothing is deleted, the history still reads true, and it shows who reset
     * and when. Other branches are not touched. Returns how many accounts
     * were reset.
     *
     * @throws CashException
     */
    public function reset(Outlet $outlet, ?string $pin): int
    {
        $this->pins->verify($outlet->id, $pin, 'reset');

        return DB::transaction(function () use ($outlet) {
            Outlet::whereKey($outlet->id)->lockForUpdate()->first();

            $lines = [];
            foreach (array_keys(self::ACCOUNT_NAMES) as $account) {
                $balance = $this->balance($outlet->id, $account);
                if (abs($balance) >= 0.01) {
                    $lines[] = ['account' => $account, 'type' => CashEntryType::RESET, 'amount' => -$balance];
                }
            }

            if ($lines) {
                $this->post($outlet->id, $lines, ['note' => 'Reset to zero']);
            }

            return count($lines);
        });
    }

    // ------------------------------------------------------------ reading

    /**
     * The day's statement for one branch: for each account, the real opening
     * balance - whatever it held at midnight, so yesterday's closing - that
     * day's movements by kind, and the closing balance. The cash drawer (notes),
     * the till's card/MFS e-money, bKash, Nagad and Recharge each stand on
     * their own; only the grand total adds them up.
     */
    public function statement(Outlet $outlet, Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end   = $start->copy()->addDay();

        $sums = CashEntry::where('outlet_id', $outlet->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('account', 'type')
            ->selectRaw('account, type, SUM(amount) as total')
            ->toBase()
            ->get();

        $counts = CashCount::with(['creator:id,name', 'countedBy:id,name'])
            ->where('outlet_id', $outlet->id)
            ->where('created_at', '<', $end)
            ->whereIn('id', CashCount::where('outlet_id', $outlet->id)
                ->where('created_at', '<', $end)
                ->groupBy('account')
                ->selectRaw('MAX(id)'))
            ->get()
            ->keyBy('account');

        $account = function (int $account) use ($outlet, $start, $sums, $counts) {
            $rows = $sums->filter(fn($row) => (int) $row->account === $account);
            $sum  = fn(int $type) => round((float) $rows->filter(fn($row) => (int) $row->type === $type)->sum('total'), 2);

            $opening = $this->balance($outlet->id, $account, $start);
            $count   = $counts->get($account);

            return [
                'opening'       => $opening,
                'pos_sales'     => $sum(CashEntryType::POS_SALE),
                'pos_reversals' => $sum(CashEntryType::POS_SALE_REVERSAL),
                'pos_method'    => round($sum(CashEntryType::POS_METHOD_IN) + $sum(CashEntryType::POS_METHOD_OUT), 2),
                'cash_in'       => $sum(CashEntryType::MFS_CASH_IN),
                'cash_out'      => $sum(CashEntryType::MFS_CASH_OUT),
                'recharge'      => $sum(CashEntryType::MFS_RECHARGE),
                'added'         => $sum(CashEntryType::ADD),
                'withdrawn'     => $sum(CashEntryType::WITHDRAW),
                'transfer_in'   => $sum(CashEntryType::TRANSFER_IN),
                'transfer_out'  => $sum(CashEntryType::TRANSFER_OUT),
                'variance'      => $sum(CashEntryType::COUNT_VARIANCE),
                'reversals'     => $sum(CashEntryType::REVERSAL),
                'reset'         => $sum(CashEntryType::RESET),
                'closing'       => round($opening + (float) $rows->sum('total'), 2),
                'last_count'    => $count ? $this->countRow($count) : null,
            ];
        };

        $drawer = $account(CashAccount::DRAWER);
        $notes  = $drawer['closing'];
        $emoney = 0.0;
        $mfs    = [];

        if ($outlet->mfs_enabled) {
            foreach (self::MFS as $provider => $pair) {
                $sim  = $account($pair['sim']);
                $cash = $account($pair['cash']);

                $mfs[$provider] = [
                    'sim'           => $sim,
                    'cash'          => $cash,
                    'total_opening' => round($sim['opening'] + $cash['opening'], 2),
                    'total_closing' => round($sim['closing'] + $cash['closing'], 2),
                ];
                $notes  += $cash['closing'];
                $emoney += $sim['closing'];
            }
        }

        return [
            'drawer'      => $drawer,
            // The day's till sales and its month's, by payment method - card
            // and MFS among them, as sales: that money is never the branch's
            // to hold, so it has no balance and is not in the grand total.
            'sales'       => $this->sales($outlet, $start),
            'mfs'         => $mfs,
            // What the branch holds: notes, and e-money on its agent SIMs.
            'grand_total' => [
                'notes'  => round($notes, 2),
                'emoney' => round($emoney, 2),
                'total'  => round($notes + $emoney, 2),
            ],
            'counts'      => $this->counts($outlet, $start),
        ];
    }

    /**
     * What the branch sold at the till on one day and in that day's month so
     * far: paid sales that still stand, by payment method, with what their
     * units cost to buy and so the profit.
     *
     * Read from the orders, as every sales report is, so the figures agree
     * with them: a sale taken back on a later day leaves the day it was sold
     * (the drawer, which follows the notes, shows them leaving on the day
     * they left).
     */
    public function sales(Outlet $outlet, Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end   = $start->copy()->addDay();
        $month = $start->copy()->startOfMonth();

        // Sales taken back with Reverse during the day, whenever they were sold.
        $reversed = PosSaleChange::where('outlet_id', $outlet->id)
            ->where('action', PosSaleChange::REVERSE)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) as count, SUM(amount) as total')
            ->toBase()
            ->first();

        return [
            'day'      => $this->salesBetween($outlet, $start, $end),
            'month'    => $this->salesBetween($outlet, $month, $end) + [
                'from' => $month->format('Y-m-d'),
                'to'   => $start->format('Y-m-d'),
            ],
            'reversed' => ['count' => (int) $reversed->count, 'total' => round((float) $reversed->total, 2)],
        ];
    }

    /**
     * Profit is what was taken without the tax - tax is collected for the
     * government - less each sold unit at its product's buying price. Only
     * today's buying price is on record, so editing one changes past profit
     * too; products sold with no buying price set are counted in
     * missing_cost, because their whole price would otherwise show as profit.
     */
    private function salesBetween(Outlet $outlet, Carbon $start, Carbon $end): array
    {
        $orders = Order::where('outlet_id', $outlet->id)
            ->where(fn($query) => $query->where('source', Source::POS)->orWhere('order_type', OrderType::POS))
            ->countedAsSale()
            ->where('order_datetime', '>=', $start->format('Y-m-d H:i:s'))
            ->where('order_datetime', '<', $end->format('Y-m-d H:i:s'));

        $byMethod = (clone $orders)
            ->groupBy('pos_payment_method')
            ->selectRaw('pos_payment_method, SUM(total) as total, SUM(tax) as tax, SUM(discount) as discount, COUNT(*) as orders')
            ->toBase()
            ->get()
            ->keyBy(fn($row) => (int) $row->pos_payment_method);

        $units = Stock::where('stocks.model_type', Order::class)
            ->whereIn('stocks.model_id', (clone $orders)->select('id'))
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->selectRaw('SUM(-stocks.quantity * products.buying_price) as cost, COUNT(DISTINCT CASE WHEN products.buying_price <= 0 THEN products.id END) as missing')
            ->toBase()
            ->first();

        $sum    = fn(string $field) => round((float) $byMethod->sum($field), 2);
        $method = fn(int $method) => round((float) ($byMethod->get($method)?->total ?? 0), 2);
        $cost   = round((float) ($units->cost ?? 0), 2);

        return [
            'total'        => $sum('total'),
            'count'        => (int) $byMethod->sum('orders'),
            'discount'     => $sum('discount'),
            'cost'         => $cost,
            'profit'       => round($sum('total') - $sum('tax') - $cost, 2),
            'missing_cost' => (int) ($units->missing ?? 0),
            'methods'      => [
                'cash'  => $method(PosPaymentMethod::CASH),
                'card'  => $method(PosPaymentMethod::CARD),
                'mfs'   => $method(PosPaymentMethod::MOBILE_BANKING),
                'other' => $method(PosPaymentMethod::OTHER),
            ],
        ];
    }

    /** One count as the page shows it: the notes counted and how it compared. */
    public function countRow(CashCount $count, bool $withFigures = true): array
    {
        $row = [
            'id'            => $count->id,
            'account'       => $count->account,
            'counted'       => $count->counted,
            'matched'       => abs((float) $count->variance) < 0.01,
            'denominations' => $this->denominationLines($count->denominations),
            // The employee who counted; older counts, and counts with nobody
            // picked, fall back to whoever entered them.
            'by'            => $count->countedBy?->name ?? $count->creator?->name,
            'entered_by'    => $count->creator?->name,
            'at'            => AppLibrary::datetime($count->created_at),
        ];

        // What was expected, and so the difference, only for a balance viewer:
        // a cashier who could read it would no longer be counting blind.
        if ($withFigures) {
            $row['expected'] = $count->expected;
            $row['variance'] = $count->variance;
        }

        return $row;
    }

    /**
     * The counts of one day, newest first. A cashier's own list comes
     * without the figures; see countRow().
     */
    public function counts(Outlet $outlet, Carbon $date, ?int $onlyBy = null): array
    {
        $start = $date->copy()->startOfDay();

        return CashCount::with(['creator:id,name', 'countedBy:id,name'])
            ->where('outlet_id', $outlet->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->copy()->addDay())
            ->when($onlyBy !== null, fn($query) => $query->where('created_by', $onlyBy))
            ->orderByDesc('id')
            ->get()
            ->map(fn(CashCount $count) => $this->countRow($count, $onlyBy === null))
            ->values()
            ->all();
    }

    /**
     * Notes as counted, largest first, with each line's amount:
     * [{note: 1000, pieces: 5, amount: 5000}, ...]. Empty for a typed count.
     */
    public function denominationLines(?array $denominations): array
    {
        return collect($denominations ?? [])
            ->map(fn($pieces, $note) => ['note' => (int) $note, 'pieces' => (int) $pieces, 'amount' => (int) $note * (int) $pieces])
            ->filter(fn($line) => $line['pieces'] > 0)
            ->sortByDesc('note')
            ->values()
            ->all();
    }

    /**
     * What a balance viewer should look at: till sales taken back, wrong PIN
     * tries and counts that did not match - today, or over the last 7 days -
     * and a default PIN still in use.
     */
    public function alerts(Outlet $outlet, string $period = 'week'): array
    {
        // "today": since midnight. "week": today and the six days before it,
        // whole days, so a morning look covers the same days as an evening one.
        $since = $period === 'today' ? today() : today()->subDays(6);

        $reversals = CashEntry::with('creator:id,name')
            ->where('outlet_id', $outlet->id)
            ->where('type', CashEntryType::POS_SALE_REVERSAL)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $soldAt = CashEntry::whereIn('order_id', $reversals->pluck('order_id')->filter())
            ->where('type', CashEntryType::POS_SALE)
            ->groupBy('order_id')
            ->selectRaw('order_id, MIN(created_at) as sold_at')
            ->pluck('sold_at', 'order_id');

        // Why each was taken back, as given when it was reversed (a sale
        // deleted before deleting was closed has no order left to ask).
        $reasons = Order::whereIn('id', $reversals->pluck('order_id')->filter())->pluck('reason', 'id');

        $methodChanges = PosSaleChange::with(['creator:id,name', 'order:id,order_serial_no'])
            ->where('outlet_id', $outlet->id)
            ->where('action', PosSaleChange::METHOD)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'period'         => $period === 'today' ? 'today' : 'week',
            'default_pin'    => $this->pins->usesDefault($outlet->id),
            'pos_reversals'  => $reversals->map(fn(CashEntry $entry) => [
                'id'                 => $entry->id,
                'amount'             => $entry->amount,
                'order_id'           => $entry->order_id,
                'reference'          => $entry->reference,
                'reason'             => $reasons[$entry->order_id] ?? null,
                'by'                 => $entry->creator?->name,
                'at'                 => AppLibrary::datetime($entry->created_at),
                'minutes_after_sale' => isset($soldAt[$entry->order_id])
                    ? (int) Carbon::parse($soldAt[$entry->order_id])->diffInMinutes($entry->created_at, true)
                    : null,
            ])->values(),
            // A sale's money moved to another payment method: right when the
            // till was wrong, a way to hide cash when it was not.
            'method_changes' => $methodChanges->map(fn(PosSaleChange $change) => [
                'id'        => $change->id,
                'order_id'  => $change->order_id,
                'reference' => $change->order?->order_serial_no,
                'amount'    => $change->amount,
                'from'      => trans('posPaymentMethod.' . $change->from_method),
                'to'        => trans('posPaymentMethod.' . $change->to_method),
                'reason'    => $change->reason,
                'by'        => $change->creator?->name,
                'at'        => AppLibrary::datetime($change->created_at),
            ])->values(),
            'pin_failures'  => CashPinFailure::with('user:id,name')
                ->where('outlet_id', $outlet->id)
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->get()
                ->groupBy('user_id')
                ->map(fn(Collection $failures) => [
                    'by'      => $failures->first()->user?->name,
                    'count'   => $failures->count(),
                    'last_at' => AppLibrary::datetime($failures->first()->created_at),
                ])->values(),
            // Counts that did not match the calculation, short or over.
            'shortages'     => CashCount::with(['creator:id,name', 'countedBy:id,name'])
                ->where('outlet_id', $outlet->id)
                ->where(fn($query) => $query->where('variance', '<=', -0.01)->orWhere('variance', '>=', 0.01))
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn(CashCount $count) => [
                    'account'  => $count->account,
                    'variance' => $count->variance,
                    'by'       => $count->countedBy?->name ?? $count->creator?->name,
                    'at'       => AppLibrary::datetime($count->created_at),
                ])->values(),
        ];
    }

    /**
     * The history. Balance viewers get every row with filters; anyone else
     * gets only what they posted themselves today, without count differences
     * - those would tell them what the drawer is meant to hold.
     */
    public function entries(Outlet $outlet, array $filters, bool $full): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = CashEntry::with(['creator:id,name', 'reversedBy:id,reverses_id'])
            ->where('outlet_id', $outlet->id);

        if ($full) {
            if (!empty($filters['from'])) {
                $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
            }
            if (!empty($filters['to'])) {
                $query->where('created_at', '<', Carbon::parse($filters['to'])->startOfDay()->addDay());
            }
            if (!empty($filters['account'])) {
                $query->where('account', (int) $filters['account']);
            }
            if (!empty($filters['type'])) {
                $query->where('type', (int) $filters['type']);
            }
        } else {
            $query->where('created_by', Auth::id())
                ->where('created_at', '>=', today())
                ->where('type', '!=', CashEntryType::COUNT_VARIANCE);
        }

        return $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    // ------------------------------------------------------------ helpers

    /**
     * Writes one movement - one row per line - with the branch row locked.
     * A line marked 'guard' may not take its account below zero.
     *
     * @param array<int, array{account:int, type:int, amount:float, guard?:bool, details?:array}> $lines
     * @throws CashException
     */
    private function post(int $outletId, array $lines, array $details = []): Collection
    {
        return DB::transaction(function () use ($outletId, $lines, $details) {
            Outlet::whereKey($outletId)->lockForUpdate()->first();

            $groupRef = count($lines) > 1 ? (string) Str::uuid() : null;
            $rows     = collect();

            foreach ($lines as $line) {
                $amount  = round((float) $line['amount'], 2);
                $current = $this->balance($outletId, $line['account']);
                $after   = round($current + $amount, 2);

                if (($line['guard'] ?? false) && $after < 0) {
                    throw $this->notEnough($line['account'], $current);
                }

                $rows->push(CashEntry::create(array_merge($details, $line['details'] ?? [], [
                    'outlet_id'     => $outletId,
                    'account'       => $line['account'],
                    'type'          => $line['type'],
                    'amount'        => $amount,
                    'balance_after' => $after,
                    'group_ref'     => $groupRef,
                    'created_by'    => Auth::id(),
                    'ip'            => request()->ip(),
                ])));
            }

            return $rows;
        });
    }

    /**
     * @throws CashException
     */
    private function assertActive(Outlet $outlet, int $account): void
    {
        if (in_array($account, [CashAccount::POS_CARD, CashAccount::POS_MFS], true)) {
            throw new CashException(trans('all.message.cash_emoney_not_a_balance'));
        }
        if (!in_array($account, $this->accounts($outlet), true)) {
            throw new CashException(trans('all.message.cash_account_off'));
        }
    }

    // Only balance viewers learn the figure; anyone else could otherwise find
    // the drawer's total by asking to withdraw ever smaller amounts.
    private function notEnough(int $account, float $available): CashException
    {
        $name = self::ACCOUNT_NAMES[$account];

        return new CashException($this->canSeeBalance()
            ? trans('all.message.cash_not_enough_detail', ['account' => $name, 'available' => number_format($available, 2)])
            : trans('all.message.cash_not_enough', ['account' => $name]));
    }
}
