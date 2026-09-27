<?php

namespace App\Services;

use App\Models\CashPin;
use App\Models\CashPinFailure;
use App\Support\CashException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The branch PIN that guards every movement of money out of a branch.
 *
 * Each branch has its own PIN. A branch that has never changed it has no row
 * and uses DEFAULT_PIN, so a branch created later works with no setup.
 *
 * Guessing is capped from the failure log rather than a cache counter, so a
 * cache clear cannot reset it, and it is keyed on user and branch, not IP -
 * behind the CDN every request may arrive from the same edge address.
 */
class CashPinService
{
    public const DEFAULT_PIN = '51920';

    // Five wrong tries by one person, or ten by anyone on one branch (several
    // staff accounts guessing together), within the window lock PIN actions.
    public const USER_LIMIT     = 5;
    public const BRANCH_LIMIT   = 10;
    public const WINDOW_MINUTES = 15;

    /**
     * @throws CashException when locked (429) or the PIN is wrong (422).
     */
    public function verify(int $outletId, ?string $pin, string $action): void
    {
        $locked = $this->lockedMinutes($outletId);
        if ($locked > 0) {
            throw new CashException(trans('all.message.cash_pin_locked', ['minutes' => $locked]), 429);
        }

        if ($pin !== null && $pin !== '' && $this->matches($outletId, $pin)) {
            return;
        }

        CashPinFailure::create([
            'user_id'   => Auth::id(),
            'outlet_id' => $outletId,
            'action'    => $action,
            'ip'        => request()->ip(),
        ]);

        $left = self::USER_LIMIT - $this->recentFailures('user_id', Auth::id())->count();
        if ($left <= 0 || $this->lockedMinutes($outletId) > 0) {
            throw new CashException(trans('all.message.cash_pin_locked', ['minutes' => self::WINDOW_MINUTES]), 429);
        }

        throw new CashException(trans('all.message.cash_pin_wrong', ['left' => $left]), 422);
    }

    public function matches(int $outletId, string $pin): bool
    {
        $hash = $this->hash($outletId);

        return $hash === null ? hash_equals(self::DEFAULT_PIN, $pin) : Hash::check($pin, $hash);
    }

    public function usesDefault(int $outletId): bool
    {
        $hash = $this->hash($outletId);

        return $hash === null || Hash::check(self::DEFAULT_PIN, $hash);
    }

    /**
     * @throws CashException
     */
    public function change(int $outletId, string $currentPin, string $newPin): void
    {
        $this->verify($outletId, $currentPin, 'pin_change');

        CashPin::updateOrCreate(
            ['outlet_id' => $outletId],
            ['pin_hash' => Hash::make($newPin), 'updated_by' => Auth::id()]
        );
    }

    /**
     * Minutes until PIN actions open again for the current user on this
     * branch; 0 when they are open. The window slides: the lock lifts once the
     * oldest of the failures that tripped it is older than WINDOW_MINUTES.
     */
    public function lockedMinutes(int $outletId): int
    {
        $minutes = 0;

        foreach ([['user_id', Auth::id(), self::USER_LIMIT], ['outlet_id', $outletId, self::BRANCH_LIMIT]] as [$column, $value, $limit]) {
            if ($value === null) {
                continue;
            }

            $failures = $this->recentFailures($column, $value);
            if ($failures->count() < $limit) {
                continue;
            }

            $trippedAt = $failures->sortByDesc('id')->values()->get($limit - 1)->created_at;
            $opensAt   = $trippedAt->copy()->addMinutes(self::WINDOW_MINUTES);
            $minutes   = max($minutes, (int) ceil(now()->diffInSeconds($opensAt, false) / 60));
        }

        return max(0, $minutes);
    }

    private function recentFailures(string $column, $value): \Illuminate\Support\Collection
    {
        return CashPinFailure::where($column, $value)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->get(['id', 'created_at']);
    }

    private function hash(int $outletId): ?string
    {
        return CashPin::where('outlet_id', $outletId)->value('pin_hash');
    }
}
