<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\CashQueryRequest;
use App\Http\Resources\CashEntryResource;
use App\Models\CashEntry;
use App\Models\Outlet;
use App\Models\User;
use App\Services\CashLedgerService;
use App\Services\CashPinService;
use App\Support\CashException;
use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The Cash Calculation page: each branch's shop cash, bKash and Nagad agent
 * money, and the ledger behind them.
 *
 * `cash-calculation` lets staff use the page; balances, history, counts'
 * results and alerts need `cash-calculation_balance` on top. Money leaving a
 * branch also needs that branch's PIN, checked in the service.
 */
class CashCalculationController extends AdminController implements HasMiddleware
{
    public function __construct(
        private readonly CashLedgerService $ledger,
        private readonly CashPinService $pins
    ) {
        parent::__construct();
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:cash-calculation', only: [
                'page', 'outlets', 'employees', 'summary', 'entries', 'add', 'mfs', 'count',
                'withdraw', 'transfer', 'reverse', 'mfsToggle', 'changePin', 'reset',
            ]),
            new Middleware('permission:cash-calculation_balance', only: ['alerts']),
        ];
    }

    /**
     * The branch tabs. Its own list because the outlet settings screen needs
     * the settings permission, which a cashier does not have.
     */
    public function outlets()
    {
        return response(['data' => $this->outletList()]);
    }

    /**
     * Who can be named as having counted: the active employees, as the POS
     * "Sale by" picker lists them. Its own list for the same reason as
     * outlets(): the Employees screen needs a permission a cashier lacks.
     */
    public function employees()
    {
        return response(['data' => $this->employeeList()]);
    }

    /**
     * The whole page in one answer - branches, employees, the day's statement,
     * its history and the watch list - so a slow line pays for one round trip
     * instead of four, one after another. The same pieces as the endpoints
     * below, which stay for paging the history and switching the watch list.
     * No outlet_id: the first branch.
     */
    public function page(CashQueryRequest $request)
    {
        return $this->attempt(function () use ($request) {
            $outlets = $this->outletList();
            $ids     = $outlets->pluck('id');
            $id      = $ids->contains($request->integer('outlet_id')) ? $request->integer('outlet_id') : $ids->first();
            $outlet  = $id ? Outlet::find($id) : null;
            $full    = $this->ledger->canSeeBalance();
            $date    = $this->date($request);

            $data = [
                'outlets'   => $outlets,
                'employees' => $this->employeeList(),
                'summary'   => null,
                'entries'   => ['data' => [], 'meta' => null],
                'alerts'    => null,
            ];

            if ($outlet) {
                $data['summary'] = $this->summaryData($outlet, $date, $full);
                $data['entries'] = $this->entryPage($request, $outlet, $full, [
                    ...$request->safe()->only(['account', 'type']),
                    'from'     => $date->format('Y-m-d'),
                    'to'       => $date->format('Y-m-d'),
                    'per_page' => 25,
                ])->response()->getData(true);
                $data['alerts'] = $full
                    ? $this->ledger->alerts($outlet, (string) $request->input('period', 'today'))
                    : null;
            }

            return response(['data' => $data]);
        });
    }

    public function summary(CashQueryRequest $request)
    {
        return $this->attempt(fn() => response(['data' => $this->summaryData(
            Outlet::findOrFail($request->integer('outlet_id')),
            $this->date($request),
            $this->ledger->canSeeBalance()
        )]));
    }

    public function entries(CashQueryRequest $request)
    {
        return $this->attempt(fn() => $this->entryPage(
            $request,
            Outlet::findOrFail($request->integer('outlet_id')),
            $this->ledger->canSeeBalance(),
            $request->validated()
        ));
    }

    public function alerts(CashQueryRequest $request)
    {
        return $this->attempt(fn() => response([
            'data' => $this->ledger->alerts(
                Outlet::findOrFail($request->integer('outlet_id')),
                (string) $request->input('period', 'week')
            ),
        ]));
    }

    public function add(CashMovementRequest $request)
    {
        return $this->attempt(fn() => $this->saved($this->ledger->add(
            $this->outlet($request),
            $request->integer('account'),
            (float) $request->amount,
            $request->note
        )));
    }

    public function mfs(CashMovementRequest $request)
    {
        return $this->attempt(fn() => $this->saved($this->ledger->mfs(
            $this->outlet($request),
            $request->provider,
            $request->kind,
            (float) $request->amount,
            $request->only(['reference', 'party', 'note'])
        )));
    }

    /**
     * A count: a check of the calculation, never an entry - no balance moves.
     * Notes and coins are totalled here rather than trusting a total sent by
     * the browser; an e-money balance (a SIM, card or MFS account) is typed
     * as one figure.
     */
    public function count(CashMovementRequest $request)
    {
        return $this->attempt(function () use ($request) {
            $account       = $request->integer('account');
            $denominations = null;
            $counted       = (float) $request->counted;

            if ($request->filled('denominations') && in_array($account, CashLedgerService::NOTE_ACCOUNTS, true)) {
                $denominations = collect($request->denominations)
                    ->only(array_map('strval', CashLedgerService::DENOMINATIONS))
                    ->map(fn($quantity) => (int) $quantity)
                    ->filter()
                    ->all();
                $counted = (float) collect($denominations)->map(fn($quantity, $note) => $quantity * (int) $note)->sum();
            }

            $countedBy = $request->filled('counted_by_id') ? $request->integer('counted_by_id') : null;
            $count     = $this->ledger->count($this->outlet($request), $account, $counted, $denominations, $countedBy);

            // Everyone sees the notes they counted, their total and whether it
            // matches. Only a balance viewer sees what was expected and the
            // difference - otherwise the next count would not be blind.
            return response([
                'status' => true,
                'data'   => $this->ledger->countRow($count, $this->ledger->canSeeBalance()),
            ]);
        });
    }

    public function withdraw(CashMovementRequest $request)
    {
        return $this->attempt(fn() => $this->saved($this->ledger->withdraw(
            $this->outlet($request),
            $request->integer('account'),
            (float) $request->amount,
            $request->party,
            $request->note,
            $request->pin
        )));
    }

    public function transfer(CashMovementRequest $request)
    {
        return $this->attempt(fn() => $this->saved($this->ledger->transfer(
            $this->outlet($request),
            $request->integer('from_account'),
            $request->integer('to_account'),
            (float) $request->amount,
            $request->note,
            $request->pin
        )));
    }

    public function reverse(CashMovementRequest $request, CashEntry $cashEntry)
    {
        return $this->attempt(fn() => $this->saved($this->ledger->reverse($cashEntry, $request->note, $request->pin)));
    }

    public function mfsToggle(CashMovementRequest $request)
    {
        return $this->attempt(function () use ($request) {
            $outlet = $this->ledger->setMfs($this->outlet($request), $request->boolean('enabled'), $request->pin);

            return response(['status' => true, 'data' => ['mfs_enabled' => (bool) $outlet->mfs_enabled]]);
        });
    }

    /**
     * Settings -> Reset: every balance of the branch to zero, with the PIN.
     */
    public function reset(CashMovementRequest $request)
    {
        return $this->attempt(function () use ($request) {
            $accounts = $this->ledger->reset($this->outlet($request), $request->pin);

            return response(['status' => true, 'data' => ['accounts_reset' => $accounts]]);
        });
    }

    public function changePin(CashMovementRequest $request)
    {
        return $this->attempt(function () use ($request) {
            $this->pins->change($request->integer('outlet_id'), $request->current_pin, (string) $request->new_pin);

            return response(['status' => true]);
        });
    }

    private function outletList()
    {
        return Outlet::where('status', Status::ACTIVE)
            ->orderBy('id')
            ->get(['id', 'name', 'mfs_enabled'])
            ->map(fn(Outlet $outlet) => [
                'id'          => $outlet->id,
                'name'        => $outlet->name,
                'mfs_enabled' => (bool) $outlet->mfs_enabled,
            ]);
    }

    private function employeeList()
    {
        return User::employees()
            ->where('status', Status::ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn(User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    private function summaryData(Outlet $outlet, Carbon $date, bool $full): array
    {
        $data = [
            'outlet'             => ['id' => $outlet->id, 'name' => $outlet->name, 'mfs_enabled' => (bool) $outlet->mfs_enabled],
            'date'               => $date->format('Y-m-d'),
            'is_today'           => $date->isToday(),
            // The shop's today, from the server - the page must not trust the
            // device clock, or a phone set to the wrong day shows the wrong
            // statement and hides the buttons.
            'today'              => today()->format('Y-m-d'),
            'can_see_balance'    => $full,
            'pin_locked_minutes' => $this->pins->lockedMinutes($outlet->id),
            'denominations'      => CashLedgerService::DENOMINATIONS,
        ];

        if ($full) {
            return $data + $this->ledger->statement($outlet, $date) + [
                'uses_default_pin' => $this->pins->usesDefault($outlet->id),
            ];
        }

        // Their own counts of today - notes, total, matched or not - without
        // anything that would give the expected amount away.
        $data['counts'] = $this->ledger->counts($outlet, today(), (int) auth()->id());

        return $data;
    }

    private function entryPage(CashQueryRequest $request, Outlet $outlet, bool $full, array $filters)
    {
        $request->attributes->set('cash_full', $full);

        return CashEntryResource::collection($this->ledger->entries($outlet, $filters, $full));
    }

    private function date(CashQueryRequest $request): Carbon
    {
        return $request->filled('date') ? Carbon::createFromFormat('Y-m-d', $request->date)->startOfDay() : today();
    }

    private function outlet(CashMovementRequest $request): Outlet
    {
        return Outlet::findOrFail($request->integer('outlet_id'));
    }

    private function saved($entries)
    {
        request()->attributes->set('cash_full', $this->ledger->canSeeBalance());

        return response(['status' => true, 'data' => CashEntryResource::collection($entries)]);
    }

    private function attempt(Closure $action)
    {
        try {
            return $action();
        } catch (CashException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->status());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
