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
    // the right entry is simply posted again.
    public const REVERSIBLE = [
        CashEntryType::ADD,
        CashEntryType::WITHDRAW,
        CashEntryType::TRANSFER_OUT,
        CashEntryType::TRANSFER_IN,
        CashEntryType::MFS_CASH_IN,
        CashEntryType::MFS_CASH_OUT,
        CashEntryType::MFS_RECHARGE,
        CashEntryType::COUNT_VARIANCE,
    ];

    public function __construct(private readonly CashPinService $pins)
    {
    }

    /**
     * The accounts a branch uses right now: the drawer and the till's card
     * and MFS e-money always, plus the agent-service pots while that is on.
     */
    public function accounts(Outlet $outlet): array
    {
        $accounts = [CashAccount::DRAWER, CashAccount::POS_CARD, CashAccount::POS_MFS];

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

        foreach ($wanted as $key => $amount) {
            [$outletId, $postedAccount] = array_map('intval', explode(':', $key));
            $difference = round($amount - ($posted[$key] ?? 0), 2);
            if (abs($difference) < 0.01) {
                continue;
            }

            $this->post($outletId, [[
                'account' => $postedAccount,
                'type'    => $difference > 0 ? CashEntryType::POS_SALE : CashEntryType::POS_SALE_REVERSAL,
                'amount'  => $difference,
            ]], ['order_id' => $order->id, 'reference' => $order->order_serial_no]);
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
     * A blind count. The expected figure is read under the lock, the
     * difference is posted as a variance row in the counter's name, and the
     * account then matches what is physically there.
     *
     * @throws CashException
     */
    public function count(Outlet $outlet, int $account, float $counted, ?array $denominations): CashCount
    {
        $this->assertActive($outlet, $account);

        return DB::transaction(function () use ($outlet, $account, $counted, $denominations) {
            Outlet::whereKey($outlet->id)->lockForUpdate()->first();

            $expected = $this->balance($outlet->id, $account);
            $variance = round($counted - $expected, 2);
            $entry    = null;

            if (abs($variance) >= 0.01) {
                $entry = $this->post($outlet->id, [[
                    'account' => $account,
                    'type'    => CashEntryType::COUNT_VARIANCE,
                    'amount'  => $variance,
                ]], ['note' => $variance < 0 ? 'Count short' : 'Count over'])->first();
            }

            return CashCount::create([
                'outlet_id'     => $outlet->id,
                'account'       => $account,
                'expected'      => $expected,
                'counted'       => $counted,
                'variance'      => $variance,
                'denominations' => $denominations,
                'entry_id'      => $entry?->id,
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

        $counts = CashCount::with('creator:id,name')
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
                'cash_in'       => $sum(CashEntryType::MFS_CASH_IN),
                'cash_out'      => $sum(CashEntryType::MFS_CASH_OUT),
                'recharge'      => $sum(CashEntryType::MFS_RECHARGE),
                'added'         => $sum(CashEntryType::ADD),
                'withdrawn'     => $sum(CashEntryType::WITHDRAW),
                'transfer_in'   => $sum(CashEntryType::TRANSFER_IN),
                'transfer_out'  => $sum(CashEntryType::TRANSFER_OUT),
                'variance'      => $sum(CashEntryType::COUNT_VARIANCE),
                'reversals'     => $sum(CashEntryType::REVERSAL),
                'closing'       => round($opening + (float) $rows->sum('total'), 2),
                'last_count'    => $count ? $this->countRow($count) : null,
            ];
        };

        $drawer = $account(CashAccount::DRAWER);
        $card   = $account(CashAccount::POS_CARD);
        $pos    = $account(CashAccount::POS_MFS);
        $notes  = $drawer['closing'];
        $emoney = $card['closing'] + $pos['closing'];
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
            // Till sales paid by card or by bKash/Nagad at the counter: money
            // the shop has, but never as notes in the drawer.
            'emoney'      => [
                'card'          => $card,
                'mfs'           => $pos,
                'total_opening' => round($card['opening'] + $pos['opening'], 2),
                'total_closing' => round($card['closing'] + $pos['closing'], 2),
            ],
            'mfs'         => $mfs,
            'grand_total' => [
                'notes'  => round($notes, 2),
                'emoney' => round($emoney, 2),
                'total'  => round($notes + $emoney, 2),
            ],
            'pos_other'   => $this->otherSales($outlet, $start, $end),
            'counts'      => CashCount::with('creator:id,name')
                ->where('outlet_id', $outlet->id)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->orderByDesc('id')
                ->get()
                ->map(fn(CashCount $count) => $this->countRow($count))
                ->values(),
        ];
    }

    /** One count as the page shows it: the notes counted and how it compared. */
    public function countRow(CashCount $count): array
    {
        return [
            'id'            => $count->id,
            'account'       => $count->account,
            'counted'       => $count->counted,
            'expected'      => $count->expected,
            'variance'      => $count->variance,
            'denominations' => $this->denominationLines($count->denominations),
            'by'            => $count->creator?->name,
            'at'            => AppLibrary::datetime($count->created_at),
        ];
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
     * Till sales paid as "Other" for the day, for information only: the method
     * names no place the money went, so it cannot be kept as a balance.
     */
    private function otherSales(Outlet $outlet, Carbon $start, Carbon $end): array
    {
        $total = Order::where('outlet_id', $outlet->id)
            ->where(fn($query) => $query->where('source', Source::POS)->orWhere('order_type', OrderType::POS))
            ->where('pos_payment_method', PosPaymentMethod::OTHER)
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED])
            ->where('order_datetime', '>=', $start->format('Y-m-d H:i:s'))
            ->where('order_datetime', '<', $end->format('Y-m-d H:i:s'))
            ->sum('total');

        return ['other' => round((float) $total, 2)];
    }

    /**
     * What a balance viewer should look at: till sales taken back, wrong PIN
     * tries and short counts over the last week, and a default PIN still in
     * use.
     */
    public function alerts(Outlet $outlet): array
    {
        $since = now()->subDays(7);

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

        return [
            'default_pin'   => $this->pins->usesDefault($outlet->id),
            'pos_reversals' => $reversals->map(fn(CashEntry $entry) => [
                'id'                 => $entry->id,
                'amount'             => $entry->amount,
                'order_id'           => $entry->order_id,
                'reference'          => $entry->reference,
                'by'                 => $entry->creator?->name,
                'at'                 => AppLibrary::datetime($entry->created_at),
                'minutes_after_sale' => isset($soldAt[$entry->order_id])
                    ? (int) Carbon::parse($soldAt[$entry->order_id])->diffInMinutes($entry->created_at, true)
                    : null,
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
            'shortages'     => CashCount::with('creator:id,name')
                ->where('outlet_id', $outlet->id)
                ->where('variance', '<', 0)
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn(CashCount $count) => [
                    'account'  => $count->account,
                    'variance' => $count->variance,
                    'by'       => $count->creator?->name,
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
