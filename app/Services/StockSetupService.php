<?php

namespace App\Services;

use App\Enums\Ask;
use App\Http\Requests\StockSetupRequest;
use App\Support\CashException;
use App\Support\StockPolicy;
use Dipokhalder\Settings\Facades\Settings;

/**
 * The Stock page's one setting: may the shop sell what it does not have?
 *
 * Stored in the `stock_setup` settings group. Changing it needs the branch cash
 * PIN, so a salesman cannot quietly open the shop up to overselling - the same
 * PIN, lockout and failure log the cash drawer uses.
 */
class StockSetupService
{
    public function __construct(
        private readonly CashPinService $pins,
        private readonly StockPolicy $policy
    ) {
    }

    /** @return array{stock_cannot_be_zero: bool} */
    public function list(): array
    {
        return ['stock_cannot_be_zero' => $this->policy->cannotBeZero()];
    }

    /**
     * @return array{stock_cannot_be_zero: bool}
     * @throws CashException wrong PIN (422) or locked out (429)
     */
    public function update(StockSetupRequest $request): array
    {
        $this->pins->verify($request->integer('outlet_id'), $request->pin, 'stock_zero_switch');

        Settings::group(StockPolicy::GROUP)->set([
            StockPolicy::KEY => $request->boolean('stock_cannot_be_zero') ? Ask::YES : Ask::NO,
        ]);

        // The policy memoises per request; this request must answer with what
        // it just saved, not with what it read on the way in.
        $this->policy->forget();

        return $this->list();
    }
}
