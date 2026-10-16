<?php

namespace App\Services;

use App\Enums\Ask;
use App\Enums\Status;
use App\Events\SendLowStockTelegram;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The two rules every stock movement answers to, checked after its rows are
 * written and before its transaction commits:
 *
 * - No branch ever holds less than nothing. A movement that would leave one
 *   below zero is refused (and rolled back with everything else it wrote).
 *   Stock in the unassigned pool - where damage is written - answers for the
 *   whole shop: the item's total may not go below zero.
 * - An item that a movement takes from more than one down to one or none at
 *   a branch is announced in the shop chat, once the movement has committed.
 *
 * Products the shop does not count (can_purchasable = NO) are left alone.
 * A change is ['product_id', 'item_type', 'item_id', 'outlet_id', 'quantity'],
 * quantity being what the movement did to that item at that place.
 */
class StockGuard
{
    public const LOW = 1;

    /**
     * @param iterable<array> $changes
     * @param bool $refuse false for a movement that has already happened (a paid online order): announce only
     * @throws Exception 422 naming the item, the branch and what it holds
     */
    public function settle(iterable $changes, bool $refuse = true): void
    {
        $changes = collect($changes)->filter(fn($change) => (float) $change['quantity'] != 0);
        if ($changes->isEmpty()) {
            return;
        }

        $products = Product::withTrashed()->whereIn('id', $changes->pluck('product_id')->unique())->get()->keyBy('id');
        $alerts   = [];

        foreach ($changes->groupBy(fn($change) => $change['item_type'] . '|' . $change['item_id'] . '|' . ($change['outlet_id'] ?: 0)) as $lines) {
            $first   = $lines->first();
            $product = $products->get($first['product_id']);
            if (!$product || (int) $product->can_purchasable === Ask::NO) {
                continue;
            }

            $outletId = $first['outlet_id'] ?: null;
            $moved    = (int) $lines->sum('quantity');
            $left     = $this->held($first['item_type'], (int) $first['item_id'], $outletId);

            // Only a movement that took stock away can be refused for it: a
            // purchase into a branch already short still has to go through.
            if ($refuse && $moved < 0 && $left < 0) {
                throw new Exception(trans('all.message.stock_not_enough', [
                    'name'   => $this->itemName($product, $first),
                    'branch' => $outletId ? (Outlet::find($outletId)?->name ?? '') : trans('all.label.unassigned'),
                    'left'   => max(0, $left - $moved),
                ]), 422);
            }

            if ($outletId && $moved < 0 && $left - $moved > self::LOW && $left <= self::LOW) {
                $alerts[] = [
                    'product_id' => (int) $first['product_id'],
                    'item_type'  => $first['item_type'],
                    'item_id'    => (int) $first['item_id'],
                    'outlet_id'  => $outletId,
                    'left'       => max(0, $left),
                ];
            }
        }

        if ($alerts) {
            // Once the sale is saved, and after its answer has gone: the
            // Telegram API can take seconds.
            DB::afterCommit(fn() => app()->terminating(function () use ($alerts) {
                foreach ($alerts as $alert) {
                    SendLowStockTelegram::dispatch($alert);
                }
            }));
        }
    }

    /**
     * Changes made by stock rows already written (or, with $sign -1, deleted).
     * Inactive rows - goods ordered but not yet received - never counted.
     */
    public function changesOf(Collection $rows, int $sign = 1, bool $activeOnly = true): array
    {
        return $rows->filter(fn(Stock $row) => !$activeOnly || (int) $row->status === Status::ACTIVE)->map(fn(Stock $row) => [
            'product_id' => (int) $row->product_id,
            'item_type'  => $row->item_type,
            'item_id'    => (int) $row->item_id,
            'outlet_id'  => $row->outlet_id ? (int) $row->outlet_id : null,
            'quantity'   => $sign * (float) $row->quantity,
        ])->values()->all();
    }

    /** What a branch holds of an item; for the unassigned pool, what the whole shop holds. */
    public function held(string $itemType, int $itemId, ?int $outletId): int
    {
        return (int) Stock::where('status', Status::ACTIVE)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->when($outletId !== null, fn($query) => $query->where('outlet_id', $outletId))
            ->sum('quantity');
    }

    private function itemName(Product $product, array $change): string
    {
        if ($change['item_type'] === ProductVariation::class) {
            $variation = ProductVariation::find($change['item_id']);
            if ($variation) {
                return $product->name . ' (' . app(ProductVariationService::class)->ancestorsToString($variation) . ')';
            }
        }

        return $product->name;
    }
}
