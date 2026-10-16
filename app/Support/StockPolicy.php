<?php

namespace App\Support;

use App\Enums\Ask;
use Dipokhalder\Settings\Facades\Settings;

/**
 * Is the shop allowed to sell what it does not have?
 *
 * One switch, on the Stock page. On (the default): a sale beyond what a branch
 * holds is refused - at the till and on the website alike. Off: the sale goes
 * through, the stock row really does go negative, and the stock page shows 0
 * with a remark saying how many units were billed beyond stock.
 *
 * Every place that asks the question asks it here, so the till, the storefront
 * and the stock page can never disagree about which position the switch is in.
 */
class StockPolicy
{
    public const GROUP = 'stock_setup';
    public const KEY   = 'stock_cannot_be_zero';

    /** Read once per request: a list of 2,000 stock rows must not read it 2,000 times. */
    private ?bool $cannotBeZero = null;

    /**
     * True when nothing may be sold past stock.
     *
     * Defaults to true - the safe position, and how the till already behaved
     * before this switch existed. An unreadable settings table must never
     * quietly open the shop up to overselling, so any failure reads as "on".
     */
    public function cannotBeZero(): bool
    {
        if ($this->cannotBeZero !== null) {
            return $this->cannotBeZero;
        }

        try {
            // One handle, one get: a second read off the same group handle
            // comes back null, and values are stored as strings.
            $value = Settings::group(self::GROUP)->get(self::KEY);
        } catch (\Throwable) {
            $value = null;
        }

        return $this->cannotBeZero = $value === null || $value === ''
            ? true
            : (int) $value === Ask::YES;
    }

    /** Forget the memoised value - for tests, and after the switch is saved. */
    public function forget(): void
    {
        $this->cannotBeZero = null;
    }
}
